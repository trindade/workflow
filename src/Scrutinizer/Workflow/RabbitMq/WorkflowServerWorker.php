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
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use JMS\Serializer\Serializer;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Scrutinizer\RabbitMQ\Rpc\RpcError;
use Scrutinizer\Workflow\Model\ActivityTask;
use Scrutinizer\Workflow\Model\ActivityType;
use Scrutinizer\Workflow\Model\DecisionTask;
use Scrutinizer\Workflow\Model\Event;
use Scrutinizer\Workflow\Model\LogEntry;
use Scrutinizer\Workflow\Model\Tag;
use Scrutinizer\Workflow\Model\Workflow;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateActivityType;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType;
use Scrutinizer\Workflow\RabbitMq\Transport\Decision;
use Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse;
use Scrutinizer\Workflow\RabbitMq\Transport\LoggingMessage;
use Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution;

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

        $this->channel->queue_declare('workflow_execution', false, true, false, false);
        $this->channel->queue_declare('workflow_decision', false,  true, false, false);
        $this->channel->queue_declare('workflow_activity_result', false,  true, false, false);
        $this->channel->queue_declare('workflow_type', false,  true, false, false);
        $this->channel->queue_declare('workflow_activity_type', false,  true, false, false);

        $this->channel->basic_consume('workflow_execution', '', false, false, false, false, array($this, 'consumeExecution'));
        $this->channel->basic_consume('workflow_decision', '',  false, false, false, false, array($this, 'consumeDecision'));
        $this->channel->basic_consume('workflow_activity_result', '',  false, false, false, false, array($this, 'consumeActivityResult'));
        $this->channel->basic_consume('workflow_type', '',  false, false, false, false, array($this, 'consumeWorkflowType'));
        $this->channel->basic_consume('workflow_activity_type', '',  false, false, false, false, array($this, 'consumeActivityType'));

        $this->channel->exchange_declare('workflow_log', 'topic');
        list($queueName,) = $this->channel->queue_declare('', false, false, true);
        $this->channel->queue_bind($queueName, 'workflow_log');
        $this->channel->basic_consume($queueName, '', false, false, false, false, array($this, 'consumeLog'));

        $this->channel->exchange_declare('workflow_execution_events', 'topic');
    }

    public function consumeLog(AMQPMessage $message)
    {
        $this->serializer->setExclusionStrategy(null);

        /** @var $logMessage LoggingMessage */
        $logMessage = $this->serializer->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\LogMessage', 'json');

        $em = $this->registry->getManager();
        try {
            if ( ! isset($logMessage->context['execution_id'])) {
                throw new \InvalidArgumentException('There was no execution context set.');
            }

            $execution = $this->registry->getRepository('Workflow:WorkflowExecution')->findOneBy(array('id' => $logMessage->context['execution_id']));
            if (null === $execution) {
                throw new \RuntimeException(sprintf('There is no workflow execution with id "%s".', $logMessage->context['execution_id']));
            }
            unset($logMessage->context['execution_id']);

            $task = null;
            if (isset($logMessage->context['task_id'])) {
                $task = $this->registry->getRepository('Workflow:AbstractTask')->findOneBy(array('id' => $logMessage->context['task_id']));
            }

            $logEntry = new LogEntry($execution, $logMessage->message, $logMessage->context, $task);
            $em->persist($logEntry);
            $em->flush($logEntry);
        } catch (\Exception $ex) {
            $this->logger->error($ex->getMessage(), array('exception' => $ex));
        }

        $em->clear();
    }

    public function consumeWorkflowType(AMQPMessage $message)
    {
        /** @var $createWorkflow CreateWorkflowType */
        $createWorkflow = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType');

        /** @var $em EntityManager */
        $em = $this->registry->getManager();

        try {
            /** @var $workflow Workflow */
            $workflow = $em->getRepository('Workflow:Workflow')->findOneBy(array('name' => $createWorkflow->name));
            if (null === $workflow) {
                $workflow = new Workflow($createWorkflow->name, $createWorkflow->deciderQueueName);
                $em->persist($workflow);
                $em->flush($workflow);
            } else {
                if ($workflow->getDeciderQueueName() !== $createWorkflow->deciderQueueName) {
                    throw new \RuntimeException(sprintf('The workflow "%s" is already declared with the decider queue "%s".', $workflow->getDeciderQueueName()));
                }
            }

            $em->clear();

            $this->sendResponseIfRequested($message);
            $this->channel->basic_ack($message->get('delivery_tag'));
        } catch (\Exception $ex) {
            $this->handleError($message, $ex);
        }
    }

    public function consumeActivityType(AMQPMessage $message)
    {
        /** @var $createActivityType CreateActivityType */
        $createActivityType = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\CreateActivityType');

        /** @var $em EntityManager */
        $em = $this->registry->getManager();

        try {
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

            $em->clear();

            $this->sendResponseIfRequested($message);
            $this->channel->basic_ack($message->get('delivery_tag'));
        } catch (\Exception $ex) {
            $this->handleError($message, $ex);
        }
    }

    public function consumeExecution(AMQPMessage $message)
    {
        $this->serializer->setExclusionStrategy(null);

        /** @var $executionData StartWorkflowExecution */
        $executionData = $this->serializer->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution', 'json');

        $workflowRepo = $this->registry->getRepository('Workflow:Workflow');

        try {
            /** @var $workflow Workflow */
            $workflow = $workflowRepo->findOneBy(array('name' => $executionData->workflow));
            if (null === $workflow) {
                throw new \RuntimeException(sprintf('Received workflow execution for non-existent workflow "%s".', $executionData->workflow));
            }

            /** @var $em EntityManager */
            $em = $this->registry->getManager();
            $tagRepo = $em->getRepository('Workflow:Tag');

            $em->getConnection()->beginTransaction();
            try {
                $tags = $tagRepo->getOrCreateTags($executionData->tags);
                $execution = new WorkflowExecution($workflow, $executionData->input, $executionData->maxRuntime, $tags);
                $decisionTask = $execution->createDecisionTask();

                $em->persist($execution);
                $em->flush();

                $this->dispatchEvent($execution, 'execution.started');
                $this->dispatchEvent($execution, 'execution.new_decision_task', array('task_id' => $decisionTask->getId()));

                $em->clear();

                $this->dispatchDecisionTask($execution, $decisionTask);
                $this->sendResponseIfRequested($message, array('execution_id' => $execution->getId()));
                $this->channel->basic_ack($message->get('delivery_tag'));

                $em->getConnection()->commit();
            } catch (\Exception $ex) {
                $em->getConnection()->rollBack();

                throw $ex;
            }
        } catch (\Exception $ex) {
            $this->handleError($message, $ex, isset($execution) ? $execution : null);

            return;
        }
    }

    public function consumeDecision(AMQPMessage $message)
    {
        $this->serializer->setExclusionStrategy(null);

        /** @var $decisionResponse DecisionResponse */
        $decisionResponse = $this->serializer->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse', 'json');

        /** @var $execution WorkflowExecution */
        $execution = $this->registry->getRepository('Workflow:WorkflowExecution')->findOneBy(array('id' => $decisionResponse->executionId));

        /** @var $em EntityManager */
        $em = $this->registry->getManager();
        try {
            if (null === $execution) {
                throw new \InvalidArgumentException(sprintf('There was no execution with id "%s".', $decisionResponse->executionId));
            }

            $em->getConnection()->beginTransaction();
            try {
                $this->acquireLock($execution);

                /** @var $decisionTask DecisionTask */
                $decisionTask = $execution->getOpenDecisionTask()->get();
                $this->dispatchEvent($execution, 'execution.new_decision', array('nb_decisions' => count($decisionResponse->decisions), 'task_id' => $decisionTask->getId()));

                if (empty($decisionResponse->decisions) && ! $execution->hasOpenActivities() && ! $execution->hasPendingDecisionTask()) {
                    throw new \InvalidArgumentException('The decisions cannot be empty when all activities are closed.');
                }

                $decisionTask->close();

                $newActivities = array();
                foreach ($decisionResponse->decisions as $decision) {
                    /** @var $decision Decision */

                    switch ($decision->type) {
                        case Decision::TYPE_EXECUTION_SUCCEEDED:
                            $execution->setState(WorkflowExecution::STATE_SUCCEEDED);
                            $em->persist($execution);
                            $em->flush();

                            $this->dispatchEvent($execution, 'execution.status_change', array('status' => 'success', 'decision_task_id' => $decisionTask->getId()));

                            break;

                        case Decision::TYPE_SCHEDULE_ACTIVITY:
                            /** @var $activityType ActivityType */
                            $activityType = $this->registry->getRepository('Workflow:ActivityType')->findOneBy(array('name' => $decision->attributes['activity']));
                            if (null === $activityType) {
                                throw new \RuntimeException(sprintf('The activity "%s" does not exist.', $decision->attributes['activity']));
                            }

                            $newActivities[] = $activityTask = $execution->createActivityTask($activityType, $decision->getInput(), $decision->getControlData());
                            $em->persist($execution);
                            $em->flush();

                            $this->dispatchEvent($execution, 'execution.new_activity_task', array('task_id' => $activityTask->getId()));

                            break;

                        case Decision::TYPE_EXECUTION_FAILED:
                            $execution->setState(WorkflowExecution::STATE_FAILED);

                            if (isset($decision->attributes['reason'])) {
                                $execution->setFailureReason($decision->attributes['reason']);
                            }

                            $em->persist($execution);
                            $em->flush();

                            $this->dispatchEvent($execution, 'execution.status_change', array('status' => 'failed', 'decision_task_id' => $decisionTask->getId()));

                            break;

                        default:
                            throw new \RuntimeException(sprintf('Unknown decision type "%s".', $decision->type));
                    }
                }

                // If new activity results arrived between scheduling the last decision task and retrieving the decision
                // result, we need to dispatch a new decision task for these new results.
                $newDecisionTask = null;
                if ($execution->hasPendingDecisionTask() && $execution->isOpen()) {
                    $newDecisionTask = $execution->createDecisionTask();
                    $execution->setPendingDecisionTask(false);

                    $em->persist($execution);
                    $em->flush();

                    $this->dispatchEvent($execution, 'execution.new_decision_task', array('task_id' => $newDecisionTask->getId()));
                }

                $em->clear();

                $this->dispatchDecisionTask($execution, $newDecisionTask);
                $this->sendResponseIfRequested($message);
                $this->channel->basic_ack($message->get('delivery_tag'));

                $em->getConnection()->commit();

                // We need to dispatch new activities only after the transaction has been committed as we might have
                // other workflow server workers, and if these activities finish quickly their results might be processed
                // by another worker before the transaction has been committed. In such a case, the activity would not
                // yet be visible to that other worker.
                foreach ($newActivities as $activityTask) {
                    /** @var $activityTask ActivityTask */

                    $this->channel->queue_declare($activityTask->getActivityType()->getQueueName(), false, true, false, false);
                    $this->channel->basic_publish(new AMQPMessage($activityTask->getInput(), array(
                        'correlation_id' => $activityTask->getId(),
                    )), '', $activityTask->getActivityType()->getQueueName());
                }
            } catch (\Exception $ex) {
                $em->getConnection()->rollBack();

                throw $ex;
            }
        } catch (\Exception $ex) {
            $this->handleError($message, $ex, $execution);

            return;
        }
    }

    public function consumeActivityResult(AMQPMessage $message)
    {
        $this->serializer->setExclusionStrategy(null);

        /** @var $activityResult ActivityResult */
        $activityResult = $this->serializer->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult', 'json');

        /** @var $em EntityManager */
        $em = $this->registry->getManager();
        try {
            /** @var $activityTask ActivityTask */
            $activityTask = $this->registry->getRepository('Workflow:ActivityTask')->findOneBy(array('id' => $activityResult->taskId));
            if (null === $activityTask) {
                throw new \RuntimeException(sprintf('There is no activity task with id "%s".', $activityResult->taskId));
            }

            $execution = $activityTask->getWorkflowExecution();
            $em->getConnection()->beginTransaction();
            try {
                $this->acquireLock($execution);

                $this->dispatchEvent($execution, 'execution.new_activity_result', array('status' => $activityResult->status, 'task_id' => $activityTask->getId()));

                switch ($activityResult->status) {
                    case ActivityResult::STATUS_SUCCESS:
                        $activityTask->setResult($activityResult->result);
                        break;

                    case ActivityResult::STATUS_FAILURE:
                        $activityTask->setFailureDetails($activityResult->failureReason, $activityResult->failureException);
                        break;

                    default:
                        throw new \LogicException(sprintf('Unknown activity status "%s".', $activityResult->status));
                }

                // Check if we need to schedule a new decision task. Generally, we always schedule a new decision task.
                // If there is already a decision task in progress, we cannot directly schedule another one, but we will
                // instead record that new activity has become available, and process it as soon as the currently
                // running decision task finishes. In case, that the workflow execution was terminated, we will not
                // schedule any new tasks, but just allow currently running activity tasks to report their results.
                $decisionTask = null;
                if ($execution->isOpen()) {
                    if ($execution->getOpenDecisionTask()->isDefined()) {
                        $execution->setPendingDecisionTask(true);
                    } else {
                        $decisionTask = $execution->createDecisionTask();
                    }
                }

                $em->persist($execution);
                $em->flush();

                if (null !== $decisionTask) {
                    $this->dispatchEvent($execution, 'execution.new_decision_task', array('task_id' => $decisionTask->getId()));
                }

                $em->clear();

                $this->dispatchDecisionTask($execution, $decisionTask);
                $this->sendResponseIfRequested($message);
                $this->channel->basic_ack($message->get('delivery_tag'));

                $em->getConnection()->commit();
            } catch (\Exception $ex) {
                $em->getConnection()->rollBack();

                throw $ex;
            }
        } catch (\Exception $ex) {
            $this->handleError($message, $ex, isset($activityTask) ? $activityTask->getWorkflowExecution() : null);

            return;
        }
    }

    public function run()
    {
        while (count($this->channel->callbacks) > 0) {
            $this->channel->wait();
        }
    }

    private function handleError(AMQPMessage $message, \Exception $ex, WorkflowExecution $execution = null)
    {
        $this->logger->error($ex->getMessage(), array('exception' => $ex));

        if (null !== $execution && null !== $execution->getId()) {
            // We need to re-merge this entity was we might have already cleared the entity manager before the
            // error occurred which would trigger an "new entity found through relationship" error.
            $this->ensureLoadedWhile($execution, function(WorkflowExecution $execution) use ($ex) {
                $this->dispatchEvent($execution, 'execution.error_occurred', array('message' => $ex->getMessage()));
            });
        }

        $this->registry->resetManager();

        if ($message->has('reply_to')) {
            $this->channel->basic_publish(
                new AMQPMessage(
                    $this->serialize(new RpcError($ex->getMessage())),
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

    /**
     * Acquires a lock on the WorkflowExecution.
     *
     * This ensures that there is only one server worker operating on a given workflow execution.
     *
     * @param \Scrutinizer\Workflow\Model\WorkflowExecution $execution
     */
    private function acquireLock(WorkflowExecution $execution)
    {
        /** @var $em EntityManager */
        $em = $this->registry->getManager();
        $em->lock($execution, LockMode::PESSIMISTIC_WRITE);
        $em->refresh($execution);
    }

    private function sendResponseIfRequested(AMQPMessage $message, array $data = array())
    {
        if ( ! $message->has('reply_to')) {
            return;
        }

        $body = json_encode($data, JSON_FORCE_OBJECT);
        $this->channel->basic_publish(new AMQPMessage($body, array(
            'correlation_id' => $message->get('correlation_id'),
        )), '', $message->get('reply_to'));
    }

    private function serialize($data, array $groups = array())
    {
        $this->serializer->setExclusionStrategy(empty($groups) ? null : new GroupsExclusionStrategy($groups));

        return $this->serializer->serialize($data, 'json');
    }

    private function deserialize($data, $type)
    {
        $this->serializer->setExclusionStrategy(null);

        return $this->serializer->deserialize($data, $type, 'json');
    }

    private function dispatchEvent(WorkflowExecution $execution, $name, array $attributes = array())
    {
        $event = new Event($execution, $name, $attributes);
        $this->persistAndDetach($event);

        $this->channel->basic_publish(
            new AMQPMessage($this->serialize($event, array('Default', 'event'))),
            'workflow_execution_events',
            'status.success'
        );
    }

    private function ensureLoadedWhile($entity, \Closure $closure)
    {
        /** @var $em EntityManager */
        $em = $this->registry->getManager();

        $loaded = $em->contains($entity);
        if ( ! $loaded) {
            $class = get_class($entity);
            $entity = $em->find($class, $em->getMetadataFactory()->getMetadataFor($class)->getIdentifierValues($entity));
        }

        $closure($entity);

        if ( ! $loaded) {
            $em->detach($entity);
        }
    }

    private function persistAndDetach($entity)
    {
        $em = $this->registry->getManager();
        $em->persist($entity);
        $em->flush($entity);
        $em->detach($entity);
    }

    private function dispatchDecisionTask(WorkflowExecution $execution, DecisionTask $task = null)
    {
        if (null === $task) {
            return;
        }

        $deciderQueueName = $execution->getWorkflow()->getDeciderQueueName();
        $this->channel->queue_declare($deciderQueueName, false, true, false, false);

        $this->serializer->setExclusionStrategy(new GroupsExclusionStrategy(array('Default', 'decider')));
        $msgBody = $this->serializer->serialize($execution, 'json');
        $this->channel->basic_publish(new AMQPMessage($msgBody), '', $deciderQueueName);
    }
}