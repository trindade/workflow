<?php

namespace Scrutinizer\Workflow\Server\Controller;

use Scrutinizer\Workflow\Model\AdoptionTask;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\Model\WorkflowExecutionTask;
use Scrutinizer\Workflow\RabbitMq\Transport\AdoptionRequest;
use Scrutinizer\Workflow\RabbitMq\Transport\ListWorkflowExecutions;
use Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\TerminateWorkflowExecution;
use Scrutinizer\Workflow\Server\AbstractController;
use Scrutinizer\Workflow\Server\Annotation as Controller;
use Scrutinizer\Workflow\Server\Request;
use Scrutinizer\Workflow\Server\Response;

class WorkflowExecutionController extends AbstractController
{
    /**
     * @Controller\ActionName("list_workflow_executions")
     */
    public function listAction(Request $request, Response $response)
    {
        /** @var $listExecutions ListWorkflowExecutions */
        $listExecutions = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\ListWorkflowExecutions');

        $em = $this->getEntityManager();

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

        $response
            ->setSerializerGroups(array('Listing'))
            ->setResponseData(array(
                'executions' => $executions,
                'count' => count($executions),
                'page' => $page,
                'per_page' => $perPage,
            ))
        ;
    }

    /**
     * @Controller\ActionName("terminate_workflow_execution")
     */
    public function terminateAction(Request $request, Response $response)
    {
        /** @var $terminateExecution TerminateWorkflowExecution */
        $terminateExecution = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\TerminateWorkflowExecution');

        $em = $this->getEntityManager();

        /** @var $execution WorkflowExecution */
        $execution = $this->getWorkflowExecutionRepo()->getByIdExclusive($terminateExecution->executionId);
        $response->setWorkflowExecution($execution);

        $execution->terminate();
        $em->persist($execution);
        $em->flush();
    }

    /**
     * @Controller\ActionName("start_workflow_execution")
     */
    public function startAction(Request $request, Response $response)
    {
        /** @var $executionData StartWorkflowExecution */
        $executionData = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\StartWorkflowExecution');

        $execution = new WorkflowExecution(
            $this->getWorkflowTypeRepo()->getByName($executionData->workflow),
            $executionData->input,
            $executionData->maxRuntime,
            $this->getTagRepo()->getOrCreate($executionData->tags)
        );

        $em = $this->getEntityManager();
        $em->persist($execution);
        $em->flush();

        $response->setWorkflowExecution($execution);
        $response->setResponseData(array('execution_id' => $execution->getId()));
    }

    /**
     * @Controller\ActonName("adopt_workflow_execution")
     */
    public function adoptAction(Request $request, Response $response)
    {
        /** @var $adoptionRequest AdoptionRequest */
        $adoptionRequest = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\AdoptionRequest');

        list($parentExecution, $childExecution) = $this->getWorkflowExecutionRepo()->getByIdExclusiveForAdoption(
            $adoptionRequest->parentExecutionId,
            $adoptionRequest->childExecutionId
        );

        /**
         * @var $parentExecution WorkflowExecution
         * @var $childExecution WorkflowExecution
         */

        $em = $this->getEntityManager();

        /** @var $adoptionTask AdoptionTask */
        $adoptionTask = $em->createQuery("SELECT t FROM Workflow:AdoptionTask t WHERE t.id = :id")
            ->setParameter('id', $adoptionRequest->taskId)
            ->getSingleResult();

        if ($adoptionTask->getChildWorkflowExecution() !== $childExecution) {
            throw new \LogicException(sprintf('%s does not belong to %s.', $childExecution, $adoptionTask));
        }
        if ($adoptionTask->getWorkflowExecution() !== $parentExecution) {
            throw new \LogicException(sprintf('%s does not belong to %s.', $parentExecution, $adoptionTask));
        }

        // Verify that we are not creating a cyclic graph, but maintain the tree structure. That is, the elected child
        // must not be an ancestor of the parent execution. We start with the parent execution, and perform a BFS. None
        // of the traversed nodes may be the selected child.
        $ancestors = array();
        $ancestor = $parentExecution;
        do {
            /** @var $ancestor WorkflowExecution */
            if ($ancestor === $childExecution) {
                $adoptionTask->setFailed('The child execution cannot be an ancestor of the parent execution.');
                $em->persist($parentExecution);
                $em->flush();

                return;
            }

            foreach ($ancestor->getParentWorkflowExecutionTasks() as $parentTask) {
                /** @var $parentTask WorkflowExecutionTask */
                $ancestors[] = $parentTask->getWorkflowExecution();
            }

            $ancestors = array_merge($ancestors, $ancestor->getParentWorkflowExecutionTasks()->map(function(WorkflowExecutionTask $task) {
                return $task->getWorkflowExecution();
            })->toArray());
        } while ($ancestor = array_shift($ancestors));

        // Verify that we have not already adopted the selected execution.
        if ($adoptionTask->isOpen()) {
            foreach ($parentExecution->getTasks() as $parentTask) {
                if ( ! $parentTask instanceof WorkflowExecutionTask) {
                    continue;
                }

                if ($parentTask->getChildWorkflowExecution() === $childExecution) {
                    $adoptionTask->setFailed('The child execution has already been adopted.');
                    $em->persist($parentExecution);
                    $em->flush();

                    return;
                }
            }
        }

        $adoptionTask->setSucceeded();
        $em->persist($parentExecution);
        $em->flush();

        if (null !== $newDecisionTask = $parentExecution->scheduleDecisionTask()) {
            $em->persist($parentExecution);
            $em->flush();

            $this->dispatchEvent($builder, $parentExecution, 'execution.new_decision_task', array(
                'task_id' => (string) $newDecisionTask->getId(),
            ));
            $this->dispatchDecisionTask($builder, $parentExecution, $newDecisionTask);
        }
    }
}