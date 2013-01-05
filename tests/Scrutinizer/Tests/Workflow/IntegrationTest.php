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

namespace Scrutinizer\Tests\Workflow;

use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\SchemaTool;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use PhpAmqpLib\Connection\AMQPConnection;
use Scrutinizer\RabbitMQ\Rpc\RpcClient;
use Scrutinizer\RabbitMQ\Util\DsnUtils;
use Scrutinizer\Workflow\Client\ExecutionScheduler;
use Scrutinizer\Workflow\Doctrine\SimpleRegistry;
use Scrutinizer\Workflow\Model\ActivityType;
use Scrutinizer\Workflow\Model\Workflow;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Symfony\Component\Process\Process;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    /** @var AMQPConnection */
    private $amqpCon;

    /** @var ExecutionScheduler */
    private $executionScheduler;

    /** @var ManagerRegistry */
    private $registry;

    /** @var EntityManager */
    private $em;

    /** @var Serializer */
    private $serializer;

    /** @var EntityRepository */
    private $executionRepo;

    private $processes = array();

    public function testSuccessfulFlowWithoutActivity()
    {
        $this->startProcess('php Fixture/successful_testflow_decider.php', __DIR__);

        $executions = array();
        for ($i=1;$i<=10;$i++) {
            $rs = $this->executionScheduler->startExecution('testflow', json_encode(array('foo' => 'bar', 'i' => $i)), array('foo', 'bar'));
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

    public function testComplexFlow()
    {
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/failing_activity_worker.php test_activity_doB');
        $this->startProcess('php Fixture/complex_testflow_decider.php');

        $executions = array();
        for ($i=1; $i<=10; $i++) {
            $rs = $this->executionScheduler->startExecution('testflow', 'foo');
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
            $this->assertCount(13, $execution->getTasks());
        }
    }

    public function testHighlyConcurrentEnvironment()
    {
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/successful_activity_worker.php test_activity_doA');
        $this->startProcess('php Fixture/highly_concurrent_decider.php');
        $this->startProcess('php Fixture/highly_concurrent_decider.php');
        $this->startServer();
        $this->startServer();

        $rs = $this->executionScheduler->startExecution('testflow', 'foo');
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
        $this->startServer();

        $this->amqpCon = DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);
        $this->purgeQueue('workflow_execution');
        $this->purgeQueue('workflow_decision');
        $this->purgeQueue('workflow_activity_result');

        $this->channel = $this->amqpCon->channel();
        $this->serializer = SerializerBuilder::create()->build();
        $this->executionScheduler = new ExecutionScheduler(new RpcClient($this->amqpCon, $this->serializer));

        $this->registry = new SimpleRegistry($_SERVER['CONFIG']);
        $this->executionRepo = $this->registry->getRepository('Workflow:WorkflowExecution');

        $this->em = $this->registry->getManager();

        $purger = new ORMPurger($this->em);
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);
        $this->em->getConnection()->exec('SET foreign_key_checks = 0');
        $purger->purge();
        $this->em->getConnection()->exec('SET foreign_key_checks = 1');

        // Create some test workflow
        $workflow = new Workflow('testflow', 'test_deciderqueue');
        $activityA = new ActivityType('doA', 'test_activity_doA');
        $activityB = new ActivityType('doB', 'test_activity_doB');
        $this->em->persist($workflow);
        $this->em->persist($activityA);
        $this->em->persist($activityB);
        $this->em->flush();

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

        $this->amqpCon->close();

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