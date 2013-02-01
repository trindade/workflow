<?php

namespace Scrutinizer\Workflow\Server\Controller;

use Scrutinizer\Workflow\Model\DecisionTask;
use Scrutinizer\Workflow\Model\Repository\WorkflowExecutionRepository;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\Decision;
use Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse;
use Scrutinizer\Workflow\Server\AbstractController;
use Scrutinizer\Workflow\Server\Annotation as Controller;
use Scrutinizer\Workflow\Server\Request;
use Scrutinizer\Workflow\Server\Response;

class DecisionController extends AbstractController
{
    /**
     * @Controller\ActionName("report_decision")
     */
    public function reportAction(Request $request, Response $response)
    {
        /** @var $decisionResponse DecisionResponse */
        $decisionResponse = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\DecisionResponse');

        $em = $this->getEntityManager();

        /** @var $executionRepo WorkflowExecutionRepository */
        $executionRepo = $em->getRepository('Workflow:WorkflowExecution');

        /** @var $execution WorkflowExecution */
        $execution = $executionRepo->getByIdExclusive($decisionResponse->executionId);
        $response->setWorkflowExecution($execution);

        /** @var $decisionTask DecisionTask */
        $decisionTask = $execution->getOpenDecisionTask()->get();
        $decisionTask->close(count($decisionResponse->decisions));

        // If an execution has been closed while a decision was in progress (for example through termination of a
        // workflow execution), we ignore the result of the decision task.
        if ($execution->isClosed()) {
            $em->persist($execution);
            $em->flush();

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
                    break;

                case Decision::TYPE_EXECUTION_CANCELED:
                    $execution->setCanceled($decision->getAttribute('details')->getOrElse(array()));
                    break;

                case Decision::TYPE_ADOPT_EXECUTION:
                    $childExecution = $executionRepo->getById($decision->attributes['execution_id']);
                    $execution->createAdoptionTask($childExecution);

                    /*
                    $builder->queueMessage(
                        new AMQPMessage(
                            json_encode(array(
                                'parent_execution_id' => (string) $execution->getId(),
                                'child_execution_id' => (string) $childExecution->getId(),
                                'task_id' => (string) $adoptionTask->getId()
                            )),
                            array(
                                'delivery_mode' => 2,
                            )
                        ),
                        '',
                        'workflow_adoption_request'
                    );
                    */

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
                    $this->updateParentExecutions($builder, $em, $execution);

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
}