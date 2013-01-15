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
use Scrutinizer\Workflow\Model\DecisionTask;
use Scrutinizer\Workflow\Model\Event;
use Scrutinizer\Workflow\Model\LogEntry;
use Scrutinizer\Workflow\Model\Workflow;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateActivityType;
use Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType;
use Scrutinizer\Workflow\RabbitMq\Transport\Decision;
use Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse;
use Scrutinizer\Workflow\RabbitMq\Transport\ListWorkflowExecutions;
use Scrutinizer\Workflow\RabbitMq\Transport\SearchWorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\TerminateWorkflowExecution;

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
        );
        foreach ($queuesToMethods as $queueName => $methodName) {
            $this->channel->queue_declare($queueName, false, true, false, false);
            $this->channel->basic_consume($queueName, '', false, false, false, false, $this->createCallback($methodName));
        }

        $this->channel->exchange_declare('workflow_log', 'topic');
        $this->channel->exchange_declare('workflow_events', 'topic');
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

    private function consumeExecutionListing(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $listExecutions ListWorkflowExecutions */
        $listExecutions = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\ListWorkflowExecutions');

        $qb = $em->createQueryBuilder();
        $qb->select('e')->from('Workflow:WorkflowExecution', 'e');
        $conditions = array();

        if ( ! empty($listExecutions->status)) {
            switch ($listExecutions->status) {
                case 'open':
                    $conditions[] = $qb->expr()->eq('e.state', ':state');
                    $qb->setParameter('state', WorkflowExecution::STATE_OPEN);
                    break;

                case 'closed':
                    $conditions[] = $qb->expr()->neq('e.state', ':state');
                    $qb->setParameter('state', WorkflowExecution::STATE_OPEN);
                    break;

                default:
                    throw new \InvalidArgumentException(sprintf('The status "%s" is not supported. Supported stati: "open", "closed"', $listExecutions->status));
            }
        }

        if ( ! empty($listExecutions->tags)) {
            $qb->innerJoin('e.tags', 't');
            $conditions[] = $qb->expr()->in('t.name', ':tags');
            $qb->setParameter('tags', $listExecutions->tags);
        }

        if ( ! empty($listExecutions->workflows)) {
            $qb->innerJoin('e.workflow', 'w');
            $conditions[] = $qb->expr()->in('w.name', ':workflows');
            $qb->setParameter('workflows', $listExecutions->workflows);
        }

        if ( ! empty($conditions)) {
            $qb->where(call_user_func_array(array($qb->expr(), 'andX'), $conditions));
        }

        $qb->orderBy('e.id', $listExecutions->order === ListWorkflowExecutions::ORDER_ASC ? 'ASC' : 'DESC');
        $query = $qb->getQuery();
        $query->setMaxResults($perPage = max(1, min(100, $listExecutions->perPage)));
        $query->setFirstResult($perPage * (($page = max(1, $listExecutions->page)) - 1));
        $executions = $query->getResult();

        $builder
            ->setSerializerGroups(array('Listing'))
            ->setResponseData(array(
                'executions' => $executions,
                'count' => count($executions),
                'page' => $page,
                'per_page' => $perPage,
            ))
        ;
    }

    private function consumeExecutionTermination(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $terminateExecution TerminateWorkflowExecution */
        $terminateExecution = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\TerminateWorkflowExecution');

        /** @var $execution WorkflowExecution */
        $execution = $em->getRepository('Workflow:WorkflowExecution')->getByIdExclusive($terminateExecution->executionId);
        $builder->setWorkflowExecution($execution);

        $execution->setTerminated();
        $em->persist($execution);
        $em->flush();

        $this->dispatchEvent($builder, $execution, 'execution.terminated');
        $this->updateParentExecution($builder, $em, $execution);
    }

    private function consumeWorkflowType(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $createWorkflow CreateWorkflowType */
        $createWorkflow = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType');

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

    private function consumeExecution(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $executionData StartWorkflowExecution */
        $executionData = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution');

        /** @var $execution WorkflowExecution */
        $execution = $em->getRepository('Workflow:WorkflowExecution')->build(
            $executionData->workflow,
            $executionData->input,
            $executionData->maxRuntime,
            $executionData->tags
        );

        $decisionTask = $execution->scheduleDecisionTask();

        $em->persist($execution);
        $em->flush();

        $builder->setWorkflowExecution($execution);
        $builder->setResponseData(array('execution_id' => $execution->getId()));

        $this->dispatchExecutionStarted($builder, $execution, $decisionTask);
        $this->dispatchDecisionTask($builder, $execution, $decisionTask);
    }

    private function consumeDecision(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $decisionResponse DecisionResponse */
        $decisionResponse = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse');

        /** @var $execution WorkflowExecution */
        $execution = $em->getRepository('Workflow:WorkflowExecution')->getByIdExclusive($decisionResponse->executionId);
        $builder->setWorkflowExecution($execution);

        /** @var $decisionTask DecisionTask */
        $decisionTask = $execution->getOpenDecisionTask()->get();
        $decisionTask->close();

        $this->dispatchEvent($builder, $execution, 'execution.new_decision', array('nb_decisions' => count($decisionResponse->decisions), 'task_id' => (string) $decisionTask->getId()));

        $em->persist($execution);
        $em->flush();

        // If an execution has been closed while a decision was in progress (for example through termination of a
        // workflow execution), we ignore the result of the decision task.
        if ($execution->isClosed()) {
            return;
        }

        if (empty($decisionResponse->decisions) && $execution->isLastDecision()) {
            throw new \InvalidArgumentException('The last decision response cannot contain an empty set of decisions.');
        }

        foreach ($decisionResponse->decisions as $decision) {
            /** @var $decision Decision */

            switch ($decision->type) {
                case Decision::TYPE_EXECUTION_SUCCEEDED:
                    $execution->setSucceeded();
                    $em->persist($execution);
                    $em->flush();

                    $this->dispatchEvent($builder, $execution, 'execution.succeeded', array('decision_task_id' => $decisionTask->getId()));
                    $this->updateParentExecution($builder, $em, $execution);

                    break;

                case Decision::TYPE_EXECUTION_CANCELED:
                    $execution->setCanceled($decision->getAttribute('details')->getOrElse(array()));
                    $em->persist($execution);
                    $em->flush();

                    $this->dispatchEvent($builder, $execution, 'execution.canceled', array('decision_task_id' => $decisionTask->getId()));
                    $this->updateParentExecution($builder, $em, $execution);

                    break;

                case Decision::TYPE_SCHEDULE_CHILD_WORKFLOW:
                    $childExecution = $em->getRepository('Workflow:WorkflowExecution')->build(
                        $decision->attributes['workflow'],
                        $decision->getInput(),
                        $decision->getAttribute('max_runtime')->getOrElse(3600),
                        $decision->getAttribute('tags')->getOrElse(array())
                    );
                    $childDecisionTask = $childExecution->scheduleDecisionTask();
                    $activityTask = $execution->createWorkflowExecutionTask($childExecution, $decision->getControl());

                    $em->persist($execution);
                    $em->flush();

                    $this->dispatchEvent($builder, $execution, 'execution.new_child_execution', array(
                        'task_id' => (string) $activityTask->getId(),
                        'child_execution_id' => (string) $childExecution->getId()
                    ));
                    $this->dispatchExecutionStarted($builder, $childExecution, $childDecisionTask);
                    $this->dispatchDecisionTask($builder, $childExecution, $childDecisionTask);

                    break;

                case Decision::TYPE_SCHEDULE_ACTIVITY:
                    /** @var $activityType ActivityType */
                    $activityType = $this->registry->getRepository('Workflow:ActivityType')->getByName($decision->attributes['activity']);
                    $activityTask = $execution->createActivityTask($activityType, $decision->getInput(), $decision->getControl());
                    $em->persist($execution);
                    $em->flush();

                    $this->channel->queue_declare($activityTask->getActivityType()->getQueueName(), false, true, false, false);

                    $activityMessage = new AMQPMessage(
                        $activityTask->getInput(),
                        array(
                            'correlation_id' => $activityTask->getId().'.'.$execution->getId(),
                            'delivery_mode' => 2,
                        )
                    );
                    $builder->queueMessage($activityMessage, '', $activityTask->getActivityType()->getQueueName());

                    $this->dispatchEvent($builder, $execution, 'execution.new_activity_task', array('task_id' => (string) $activityTask->getId()));

                    break;

                case Decision::TYPE_EXECUTION_FAILED:
                    $execution->setFailed(
                        $decision->getAttribute('reason')->getOrElse(null),
                        $decision->getAttribute('details')->getOrElse(array())
                    );

                    $em->persist($execution);
                    $em->flush();

                    $this->dispatchEvent($builder, $execution, 'execution.failed', array('decision_task_id' => $decisionTask->getId()));
                    $this->updateParentExecution($builder, $em, $execution);

                    break;

                default:
                    throw new \RuntimeException(sprintf('Unknown decision type "%s".', $decision->type));
            }
        }

        if (null !== $newDecisionTask = $execution->createDecisionTaskIfPending()) {
            $em->persist($execution);
            $em->flush();

            $this->dispatchEvent($builder, $execution, 'execution.new_decision_task', array('task_id' => (string) $newDecisionTask->getId()));
            $this->dispatchDecisionTask($builder, $execution, $newDecisionTask);
        }
    }

    private function updateParentExecution(ResponseBuilder $builder, EntityManager $em, WorkflowExecution $execution)
    {
        if (null === $parentTask = $execution->getParentWorkflowExecutionTask()) {
            return;
        }

        $parentExecution = $parentTask->getWorkflowExecution();

        $this->dispatchEvent($builder, $parentExecution, 'execution.child_execution_result', array(
            'task_id' => (string) $parentTask->getId(),
            'child_execution_id' => (string) $execution->getId(),
            'child_execution_state' => $execution->getState(),
        ));

        $parentDecisionTask = $parentExecution->scheduleDecisionTask();

        $em->persist($parentExecution);
        $em->flush();

        if (null !== $parentDecisionTask) {
            $this->dispatchEvent($builder, $parentExecution, 'execution.new_decision_task', array('task_id' => (string) $parentDecisionTask->getId()));
            $this->dispatchDecisionTask($builder, $parentExecution, $parentDecisionTask);
        }
    }

    private function consumeActivityResult(AMQPMessage $message, ResponseBuilder $builder, EntityManager $em)
    {
        /** @var $activityResult ActivityResult */
        $activityResult = $this->deserialize($message->body, 'Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult');

        /** @var $execution WorkflowExecution */
        $execution = $this->registry->getRepository('Workflow:WorkflowExecution')->getByIdExclusive($activityResult->executionId);
        $builder->setWorkflowExecution($execution);

        /** @var $activityTask ActivityTask */
        $activityTask = $execution->getActivityTaskWithId($activityResult->taskId)->get();
        $this->dispatchEvent($builder, $execution, 'execution.new_activity_result', array(
            'status' => $activityResult->status,
            'task_id' => (string) $activityTask->getId()
        ));

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

        $decisionTask = $execution->scheduleDecisionTask();
        $em->persist($execution);
        $em->flush();

        if (null !== $decisionTask) {
            $this->dispatchEvent($builder, $execution, 'execution.new_decision_task', array('task_id' => (string) $decisionTask->getId()));
            $this->dispatchDecisionTask($builder, $execution, $decisionTask);
        }
    }

    public function run()
    {
        while (count($this->channel->callbacks) > 0) {
            $this->channel->wait();
        }
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

    private function dispatchExecutionStarted(ResponseBuilder $builder, WorkflowExecution $execution, DecisionTask $task)
    {
        $this->dispatchEvent($builder, $execution, 'execution.started');
        $this->dispatchEvent($builder, $execution, 'execution.new_decision_task', array('task_id' => (string) $task->getId()));
    }

    private function dispatchEvent(ResponseBuilder $builder = null, WorkflowExecution $execution, $name, array $attributes = array())
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