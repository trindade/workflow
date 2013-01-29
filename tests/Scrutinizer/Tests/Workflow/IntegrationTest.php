<?php

/*
 * Copyright 2013 Johannes M. Schmitt <schmittjoh@gmail.com>
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(ticks = 1);

namespace Scrutinizer\Tests\Workflow;

use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use PhpAmqpLib\Connection\AMQPConnection;
use Scrutinizer\RabbitMQ\Rpc\RpcClient;
use Scrutinizer\RabbitMQ\Util\DsnUtils;
use Scrutinizer\Workflow\Client\Serializer\TaskHandler;
use Scrutinizer\Workflow\Client\WorkflowClient;
use Scrutinizer\Workflow\Doctrine\SimpleRegistry;
use Scrutinizer\Workflow\Model\Event;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\WorkflowServerWorker;
use Symfony\Component\Process\Process;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    /** @var AMQPConnection */
    private $amqpCon;

    /** @var WorkflowClient */
    private $client;

    /** @var ManagerRegistry */
    private $registry;

    /** @var EntityManager */
    private $em;

    /** @var Serializer */
    private $serializer;

    /** @var EntityRepository */
    private $executionRepo;

    private $processes = array();

    /**
     * @group garbage-collection
     */
    public function testGarbageCollection()
    {
        $rs = $this->client->startExecution('testflow', 'bar', array(), 1);

        sleep(2);

        $server = new WorkflowServerWorker($this->amqpCon, $this->registry, $this->serializer);
        $server->collectGarbage();

        $execution = $this->executionRepo->findOneBy(array('id' => $rs['execution_id']));
        $this->assertEquals(WorkflowExecution::STATE_TIMED_OUT, $execution->getState());
    }

    /**
     * @group cancel
     */
    public function testCancelExecution()
    {
        $this->startProcess('php Fixture/canceling_decider.php test_deciderqueue');

        $rs = $this->client->startExecution('testflow', '');
        $this->assertEquals(array('execution_id' => 1), $rs);

        /** @var $execution WorkflowExecution */
        $execution = $this->executionRepo->findOneBy(array('id' => 1));
        $this->assertTrueWithin(5, function() use ($execution) {
            $this->em->refresh($execution);

            return $execution->isClosed();
        });

        $this->assertEquals(WorkflowExecution::STATE_CANCELED, $execution->getState());
        $this->assertEquals(array('foo' => 'bar'), $execution->getCancelDetails());
    }

    /**
     * @group listing
     */
    public function testExecutionListing()
    {
        $this->client->startExecution('testflow', 'a', array('foo'));
        $this->client->startExecution('testflow', 'b', array('foo', 'bar'));

        $this->client->declareWorkflowType('foo', 'bar');
        $this->client->startExecution('foo', 'c', array('baz'));
        $this->client->startExecution('foo', 'd');

        $rs = $this->client->listExecutions();
        $this->assertCount(4, $rs->executions, $this->getDebugInfo());
        $this->assertEquals(4, $rs->count, $this->getDebugInfo());
        $this->assertEquals(20, $rs->perPage, $this->getDebugInfo());
        $this->assertEquals(1, $rs->page, $this->getDebugInfo());
        $this->assertEquals('d', $rs->executions[0]->input, $this->getDebugInfo());
        $this->assertEquals('a', $rs->executions[3]->input, $this->getDebugInfo());

        $rs = $this->client->listExecutions(array('testflow'));
        $this->assertCount(2, $rs->executions, $this->getDebugInfo());
        $this->assertEquals('b', $rs->executions[0]->input);
        $this->assertEquals('a', $rs->executions[1]->input);

        $rs = $this->client->listExecutions(array('testflow'), array(), null, 'asc');
        $this->assertCount(2, $rs->executions);
        $this->assertEquals('a', $rs->executions[0]->input);
        $this->assertEquals('b', $rs->executions[1]->input);

        $rs = $this->client->listExecutions(array(), array('bar'));
        $this->assertCount(1, $rs->executions);
        $this->assertEquals('b', $rs->executions[0]->input);

        $rs = $this->client->listExecutions(array(), array('mooooooo'));
        $this->assertCount(0, $rs->executions);
    }

    /**
     * @group termination
     */
    public function testExecutionIsTerminated()
    {
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/slow_decider.php test_deciderqueue');

        $rs = $this->client->startExecution('testflow', '');
        sleep(1);

        $this->assertArrayHasKey('execution_id', $rs);
        $this->assertEquals(array(), $this->client->terminateExecution($rs['execution_id']));

        sleep(6);

        /** @var $execution WorkflowExecution */
        $execution = $this->em->find('Workflow:WorkflowExecution', $rs['execution_id']);
        $this->assertCount(1, $execution->getTasks());
        $this->assertTrue($execution->getTasks()->get(0)->isClosed(), $this->getDebugInfo());

        $eventNames = array();
        foreach ($execution->getHistory() as $event) {
            /** @var $event Event */

            $eventNames[] = $event->getName();
        }
        $this->assertEquals(array('execution.started', 'execution.new_decision_task', 'execution.terminated', 'execution.new_decision'), $eventNames);
    }

    public function testSuccessfulFlowWithoutActivity()
    {
        $this->startProcess('php Fixture/successful_testflow_decider.php', __DIR__);

        $executions = array();
        for ($i=1;$i<=10;$i++) {
            $rs = $this->client->startExecution('testflow', json_encode(array('foo' => 'bar', 'i' => $i)), array('foo', 'bar'));
            $this->assertEquals(array('execution_id' => $i), $rs);
            $this->assertNotNull($executions[] = $this->executionRepo->findOneBy(array('id' => $i)));
        }

        $this->assertTrueWithin(10, function() use ($executions) {
            foreach ($executions as $execution) {
                $this->em->refresh($execution);

                if ( ! $execution->isClosed()) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @group failure
     */
    public function testComplexFlow()
    {
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/failing_activity_worker.php test_activity_doB');
        $this->startProcess('php Fixture/complex_testflow_decider.php');

        $eventLog = tempnam(sys_get_temp_dir(), 'event-log');
        $this->startProcess('php Fixture/test_listener.php '.escapeshellarg($eventLog));

        $executions = array();
        for ($i=1; $i<=10; $i++) {
            $rs = $this->client->startExecution('testflow', 'foo');
            $this->assertEquals(array('execution_id' => $i), $rs);

            /** @var $execution WorkflowExecution */
            $this->assertNotNull($executions[$i] = $this->executionRepo->findOneBy(array('id' => $i)));
        }

        $this->assertTrueWithin(20, function() use ($executions) {
            foreach ($executions as $execution) {
                $this->em->refresh($execution);

                if ( ! $execution->isClosed()) {
                    return false;
                }
            }

            return true;
        });

        foreach ($executions as $execution) {
            $this->assertTrue($execution->hasSucceeded(), $this->getDebugInfo());
            $this->assertFalse($execution->hasOpenTasks(), $this->getDebugInfo());
            $this->assertCount(13, $execution->getTasks(), $this->getDebugInfo());
        }

        $log = file_get_contents($eventLog);
        unlink($eventLog);
        $this->assertEquals($this->em->createQuery("SELECT COUNT(e) FROM Workflow:Event e")->getSingleScalarResult(), substr_count($log, "\n"), $this->getDebugInfo());
    }

    /**
     * @group concurrency
     */
    public function testHighlyConcurrentEnvironment()
    {
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/highly_concurrent_decider.php');
        $this->startProcess('php Fixture/highly_concurrent_decider.php');
        $this->startServer();
        $this->startServer();

        $rs = $this->client->startExecution('testflow', 'foo');
        $this->assertEquals(array('execution_id' => 1), $rs);

        /** @var $execution WorkflowExecution */
        $this->assertNotNull($execution = $this->executionRepo->findOneBy(array('id' => 1)));

        $this->assertTrueWithin(20, function() use ($execution) {
            $this->em->refresh($execution);

            return $execution->isClosed();
        });

        $this->assertTrue($execution->hasSucceeded(), $this->getDebugInfo());
        $this->assertFalse($execution->hasOpenTasks(), $this->getDebugInfo());
        $this->assertGreaterThan(65, count($execution->getTasks()), $this->getDebugInfo());
    }

    /**
     * @group child-workflow
     */
    public function testChildWorkflow()
    {
        $this->client->declareWorkflowType('parent_flow', 'parent_flow_decider');
        $this->client->declareWorkflowType('child_flow', 'child_flow_decider');
        $this->purgeQueue('parent_flow_decider');
        $this->purgeQueue('child_flow_decider');

        $this->startServer();
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');

        $this->startProcess('php Fixture/parent_workflow_decider.php parent_flow_decider');
        $this->startProcess('php Fixture/parent_workflow_decider.php parent_flow_decider');
        $this->startProcess('php Fixture/sequential_decider.php child_flow_decider');
        $this->startProcess('php Fixture/sequential_decider.php child_flow_decider');

        $rs = $this->client->startExecution('parent_flow', 'test');
        $this->assertEquals(array('execution_id' => 1), $rs, $this->getDebugInfo());
        $this->assertNotNull($execution = $this->executionRepo->findOneBy(array('id' => $rs['execution_id'])), $this->getDebugInfo());

        $this->assertTrueWithin(15, function() use ($execution) {
            $this->em->refresh($execution);

            return $execution->isClosed();
        });

        $this->assertCount(3, $executions = $this->executionRepo->findAll(), $this->getDebugInfo());
        $this->assertTrue($executions[0]->hasSucceeded(), $this->getDebugInfo());
        $this->assertTrue($executions[1]->hasSucceeded(), $this->getDebugInfo());
        $this->assertTrue($executions[2]->hasSucceeded(), $this->getDebugInfo());
    }

    /**
     * @group adoption
     */
    public function testAdoptExecution()
    {
        $this->client->declareWorkflowType('adopting_workflow', 'adopting_workflow_decider');
        $this->purgeQueue('adopting_workflow_decider');

        $this->startProcess('php Fixture/successful_testflow_decider.php', __DIR__);
        $this->startProcess('php Fixture/adopting_decider.php adopting_workflow_decider');

        $testExecution = $this->client->startExecution('testflow', '');

        /** @var $adoptingExecution WorkflowExecution */
        $adoptingExecution = $this->executionRepo->findOneBy(array('id' => $this->client->startExecution('adopting_workflow', $testExecution['execution_id'])['execution_id']));

        $this->assertTrueWithin(5, function() use ($adoptingExecution) {
            $this->em->refresh($adoptingExecution);

            return $adoptingExecution->isClosed();
        });

        $this->assertTrue($adoptingExecution->hasSucceeded(), $adoptingExecution->getFailureReason()."\n\n".$this->getDebugInfo());
        $this->assertCount(4, $adoptingExecution->getTasks(), $this->getDebugInfo());
    }

    public static function setUpBeforeClass()
    {
        $em = (new SimpleRegistry($_SERVER['CONFIG']))->getManager();
        $tool = new SchemaTool($em);

        try {
            $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        } catch (\Exception $ex) {
            // Ignore if database schema does not exist yet.
        }

        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function setUp()
    {
        pcntl_signal(SIGINT, function() {
            echo $this->getDebugInfo();
            exit;
        });

        $this->amqpCon = DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);

        $this->purgeQueue('workflow_execution');
        $this->purgeQueue('workflow_decision');
        $this->purgeQueue('workflow_activity_result');
        $this->purgeQueue('workflow_type');
        $this->purgeQueue('workflow_activity_type');

        $this->startServer();
        usleep(5E5);
        $this->assertTrue($this->processes[0]->isRunning(), 'Server failed to start.'."\n\n".$this->getDebugInfo());

        $this->channel = $this->amqpCon->channel();
        $this->serializer = SerializerBuilder::create()
            ->addDefaultHandlers()
            ->configureHandlers(function(HandlerRegistryInterface $registry) {
                $registry->registerSubscribingHandler(new TaskHandler());
            })
            ->build();
        $this->client = new WorkflowClient(new RpcClient($this->amqpCon, $this->serializer));

        $this->registry = new SimpleRegistry($_SERVER['CONFIG']);
        $this->executionRepo = $this->registry->getRepository('Workflow:WorkflowExecution');

        $this->em = $this->registry->getManager();

        $purger = new ORMPurger($this->em);
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);
        $this->em->getConnection()->exec('SET foreign_key_checks = 0');
        $purger->purge();
        $this->em->getConnection()->exec('SET foreign_key_checks = 1');

        // Create some test workflow
        $this->client->declareWorkflowType('testflow', 'test_deciderqueue');
        $this->client->declareActivityType('doA', 'test_activity_doA');
        $this->client->declareActivityType('doB', 'test_activity_doB');

        $this->purgeQueue('test_deciderqueue');
        $this->purgeQueue('test_activity_doA');
        $this->purgeQueue('test_activity_doB');
    }

    protected function tearDown()
    {
        $prematureExists = array();

        foreach ($this->processes as $proc) {
            /** @var Process $proc */

            if ( ! $proc->isRunning()) {
                $prematureExists[] = $proc->getCommandLine();
            }

            $proc->stop(5);
        }

        if (null !== $this->amqpCon) {
            $this->amqpCon->close();
        }

        if ( ! empty($prematureExists)) {
            throw new \InvalidArgumentException('These programs exited prematurely: '.implode(', ', $prematureExists)."\n\n".$this->getDebugInfo());
        }
    }

    private function assertTrueWithin($seconds, callable $callback)
    {
        $start = time();
        while ($start + $seconds > time()) {
            if (true === $callback()) {
                return;
            }

            usleep(2E5); // 200 ms
        }

        $this->fail(sprintf('Callback did not return true within %d seconds.', $seconds).PHP_EOL.PHP_EOL.$this->getDebugInfo());
    }

    private function getDebugInfo()
    {
        $msg = '--------------------------- PROCESS INFO ---------------------------'.PHP_EOL.PHP_EOL;

        foreach ($this->processes as $proc) {
            /** @var $proc Process */

            $msg .= $proc->getCommandLine().PHP_EOL;
            $msg .= str_repeat('=', strlen($proc->getCommandLine())).PHP_EOL;
            $msg .= $proc->getOutput().PHP_EOL;
            $msg .= $proc->getErrorOutput().PHP_EOL.PHP_EOL;
        }

        $msg .= '------------------------- END PROCESS INFO -------------------------'.PHP_EOL;

        return $msg;
    }

    private function purgeQueue($queueName)
    {
        $channel = $this->amqpCon->channel();
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->queue_purge($queueName);
        $channel->close();
    }

    private function startServer()
    {
        $rootDir = realpath(__DIR__.'/../../../../');
        $this->startProcess('php bin/scrutinizer-workflow server:run --verbose', $rootDir);
    }

    private function startProcess($cmd, $cwd = __DIR__)
    {
        $this->processes[] = $proc = new Process('exec '.$cmd, $cwd);
        $proc->start();
    }
}