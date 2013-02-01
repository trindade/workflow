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

namespace Scrutinizer\Workflow\RabbitMq;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Expr\Join;
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use JMS\Serializer\Serializer;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Scrutinizer\RabbitMQ\Rpc\RpcError;
use Scrutinizer\Workflow\Model\AbstractTask;
use Scrutinizer\Workflow\Model\ActivityTask;
use Scrutinizer\Workflow\Model\ActivityType;
use Scrutinizer\Workflow\Model\AdoptionTask;
use Scrutinizer\Workflow\Model\DecisionTask;
use Scrutinizer\Workflow\Model\Event;
use Scrutinizer\Workflow\Model\LogEntry;
use Scrutinizer\Workflow\Model\Repository\WorkflowExecutionRepository;
use Scrutinizer\Workflow\Model\Workflow;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\Model\WorkflowExecutionTask;
use Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateActivityType;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType;
use Scrutinizer\Workflow\RabbitMq\Transport\Decision;
use Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse;
use Scrutinizer\Workflow\RabbitMq\Transport\ListWorkflowExecutions;
use Scrutinizer\Workflow\RabbitMq\Transport\SearchWorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\TerminateWorkflowExecution;

/**
 * Server Worker.
 *
 * Processes messages sent by clients, schedules new tasks, keeps track of execution state, etc.
 *
 * This class has been designed for concurrency; you can run as many workers as you need to handle the amount of
 * messages in your system.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class WorkflowServerWorker
{
    private $con;
    private $channel;
    private $registry;
    private $serializer;
    private $logger;

    public function __construct(AMQPConnection $con, ManagerRegistry $registry, Serializer $serializer, LoggerInterface $logger = null)
    {
        $this->con = $con;
        $this->channel = $con->channel();
        $this->registry = $registry;
        $this->serializer = $serializer;
        $this->logger = $logger ?: new NullLogger();

        $this->channel->basic_qos(0, 5, false);

        $queuesToMethods = array(
            'workflow_execution' => 'consumeExecution',
            'workflow_execution_termination' => 'consumeExecutionTermination',
            'workflow_execution_listing' => 'consumeExecutionListing',
            'workflow_decision' => 'consumeDecision',
            'workflow_activity_result' => 'consumeActivityResult',
            'workflow_type' => 'consumeWorkflowType',
            'workflow_activity_type' => 'consumeActivityType',
            'workflow_adoption_request' => 'consumeAdoptionRequest', // Internal Queue.
        );
        foreach ($queuesToMethods as $queueName => $methodName) {
            $this->channel->queue_declare($queueName, false, true, false, false);
            $this->channel->basic_consume($queueName, '', false, false, false, false, $this->createCallback($methodName));
        }

        $this->channel->exchange_declare('workflow_log', 'topic');
        $this->channel->exchange_declare('workflow_events', 'topic');

        $dbCon = $this->registry->getConnection();
        if (false === $dbCon->query("SELECT id FROM workflow_execution_lock")->fetchColumn()) {
            $dbCon->exec("INSERT INTO workflow_execution_lock (id) VALUES (1)");
        }
    }

    /**
     * Creates a callback for an internal method.
     *
     * This method also adds some common clean-up tasks which need to be performed after each consumption.
     *
     * @param string $method
     *
     * @return callable
     */
    private function createCallback($method)
    {
        return function (AMQPMessage $message) use ($method) {
            $builder = new ResponseBuilder();

            /** @var $em EntityManager */
            $em = $this->registry->getManager();

            $con = $em->getConnection();
            if ($con->isTransactionActive()) {
                throw new \LogicException('A transaction must not be active.');
            }

            // Some database systems like Mysql's InnoDB use an isolation level of "REPEATABLE READ" by default. For
            // our purposes we specifically need the "READ COMMITTED" isolation level so that other workers can see
            // new tasks which were created while they waited for their lock.
            $con->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $con->beginTransaction();
            try {
                $this->$method($message, $builder, $em);
                $con->commit();

                foreach ($builder->getMessages() as $queuedMessage) {
                    call_user_func_array(array($this->channel, 'basic_publish'), $queuedMessage);
                }

                if ($message->has('reply_to')) {
                    $body = $this->serialize($builder->getResponseData(), $builder->getSerializerGroups());
                    $this->channel->basic_publish(new AMQPMessage($body, array(
                        'correlation_id' => $message->get('correlation_id'),
                    )), '', $message->get('reply_to'));
                }

                $this->channel->basic_ack($message->get('delivery_tag'));

                $em->clear();
            } catch (\Exception $ex) {
                $con->rollBack();
                $em->close();
                $this->registry->resetManager();

                $this->logger->error($ex->getMessage().' Context: '.$ex->getFile().' on line '.$ex->getLine(), array('exception' => $ex));

                $execution = $builder->getWorkflowExecution();
                if (null !== $execution && null !== $execution->getId()) {
                    $this->dispatchEvent(null, $execution, 'execution.error_occurred', array(
                        'message' => $ex->getMessage(),
                    ));
                }

                if ($message->has('reply_to')) {
                    $this->channel->basic_publish(
                        new AMQPMessage(
                            'scrutinizer.rpc_error:'.$this->serialize(new RpcError($ex->getMessage(), array(
                            ))),
                            array('correlation_id' => $message->get('correlation_id'))
                        ),
                        '',
                        $message->get('reply_to')
                    );
                    $this->channel->basic_ack($message->get('delivery_tag'));

                    return;
                }

                // If there is no reply-to queue, then we will not acknowledge the message, and hope
                // that the next worker it will be assigned to will complete successfully. Should that
                // not be the case, the message will eventually be discarded, and the execution will
                // be garbage collected by one of the server workers.
                $this->channel->basic_nack($message->get('delivery_tag'));
            }
        };
    }

    private function consumeActivityType(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $createActivityType CreateActivityType */
        $createActivityType = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\CreateActivityType');

        /** @var $activityType ActivityType */
        $activityType = $em->getRepository('Workflow:ActivityType')->findOneBy(array('name' => $createActivityType->name));
        if (null === $activityType) {
            $activityType = new ActivityType($createActivityType->name, $createActivityType->queueName);
            $em->persist($activityType);
            $em->flush($activityType);
        } else {
            if ($activityType->getQueueName() !== $createActivityType->queueName) {
                throw new \RuntimeException(sprintf('The workflow "%s" is already declared with the queue "%s".', $activityType->getQueueName()));
            }
        }
    }

    private function consumeDecision(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
    }

    public function collectGarbage()
    {
        /** @var $em EntityManager */
        $em = $this->registry->getManager();
        $con = $em->getConnection();

        switch ($dbPlatform = $con->getDatabasePlatform()->getName()) {
            case 'mysql':
                $sql = 'SELECT id FROM workflow_executions e
                        WHERE
                            e.state = "'.WorkflowExecution::STATE_OPEN.'"
                            AND
                            DATE_ADD(e.createdAt, INTERVAL e.maxRuntime SECOND) < :now
                        ';
                break;

            default:
                throw new \LogicException(sprintf('Unsupported database platform "%s".', $dbPlatform));
        }

        $executionIds = $con->executeQuery($sql, array('now' => new \DateTime()), array('now' => \Doctrine\DBAL\Types\Type::DATETIME));

        /** @var $executionRepo WorkflowExecutionRepository */
        $executionRepo = $em->getRepository('Workflow:WorkflowExecution');

        foreach ($executionIds as $data) {
            $con->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $con->beginTransaction();
            try {
                $builder = new ResponseBuilder();
                $execution = $executionRepo->getByIdExclusive($data['id']);
                $execution->setTimedOut();

                $this->dispatchEvent($builder, $execution, 'execution.timed_out');
                $this->updateParentExecutions($builder, $em, $execution);

                $em->persist($execution);
                $em->flush();

                $con->commit();

                foreach ($builder->getMessages() as $messageParams) {
                    call_user_func_array(array($this->channel, 'basic_publish'), $messageParams);
                }

            } catch (\Exception $ex) {
                $con->rollBack();

                throw $ex;
            }
        }
    }

    public function run()
    {
        while (count($this->channel->callbacks) > 0) {
            $this->channel->wait();
        }
    }

    private function dispatchExecutionStarted(ResponseBuilder $builder, WorkflowExecution $execution, DecisionTask $task)
    {
        $this->dispatchEvent($builder, $execution, 'execution.started');
        $this->dispatchEvent($builder, $execution, 'execution.new_decision_task', array('task_id' => (string) $task->getId()));
    }

    public function dispatchEvent(ResponseBuilder $builder = null, WorkflowExecution $execution, $name, array $attributes = array())
    {
        /** @var $con Connection */
        $con = $this->registry->getConnection();
        $con->executeQuery("INSERT INTO workflow_events (name, attributes, createdAt, workflowExecution_id)
                            VALUES (:name, :attributes, :createdAt, :workflowExecutionId)", array(
            'name' => $name,
            'attributes' => json_encode($attributes, JSON_FORCE_OBJECT),
            'createdAt' => new \Datetime(),
            'workflowExecutionId' => $execution->getId(),
        ), array(
            'createdAt' => Type::DATETIME
        ));

        $publishArgs = array(
            new AMQPMessage(
                $this->serialize(new Event($execution, $name, $attributes), array('Default', 'Details')),
                array(
                    'delivery_mode' => 2,
                )
            ),
            'workflow_events',
            $name
        );

        if (null === $builder) {
            call_user_func_array(array($this->channel, 'basic_publish'), $publishArgs);

            return;
        }

        call_user_func_array(array($builder, 'queueMessage'), $publishArgs);
    }

    private function dispatchDecisionTask(ResponseBuilder $builder, WorkflowExecution $execution, DecisionTask $task)
    {
        $deciderQueueName = $execution->getWorkflow()->getDeciderQueueName();
        $this->channel->queue_declare($deciderQueueName, false, true, false, false);

        $message = new AMQPMessage(
            $this->serialize($execution, array('Default', 'Details')),
            array(
                'delivery_mode' => 2,
            )
        );
        $builder->queueMessage($message, '', $deciderQueueName);
    }
}