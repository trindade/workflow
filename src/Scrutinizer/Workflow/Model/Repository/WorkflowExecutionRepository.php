<?php

namespace Scrutinizer\Workflow\Model\Repository;

use Doctrine\ORM\EntityRepository;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class WorkflowExecutionRepository extends EntityRepository
{
    public function build($workflowName, $input, $maxRuntime = 3600, array $tags = array())
    {
        return new WorkflowExecution(
            $this->_em->getRepository('Workflow:Workflow')->getByName($workflowName),
            $input,
            $maxRuntime,
            $this->_em->getRepository('Workflow:Tag')->getOrCreate($tags)
        );
    }

    /**
     * Retrieves a workflow execution for exclusive edit access.
     *
     * This method ensures that no other workers are allowed to edit this workflow execution, or one of its parent
     * executions at the same time.
     *
     * We need to make sure to always lock the top-most execution even if we are only modifying a child execution.
     * For example, if we have a workflow A which spawns two childs B and C. When B, and C are closed at the same
     * time, and we already acquired locks for B, and C in different workers. Then, both of these workers would try
     * to then acquire a lock for A to dispatch a new event/decision task. However, in such a case neither B, nor C
     * could ever acquire a lock for the parent execution A, and we would end-up in a deadlock condition.
     *
     * This method expects that a transaction is already created, and will throw an exception if that is not the case.
     *
     * @param string $id
     *
     * @return WorkflowExecution
     */
    public function getByIdExclusive($id)
    {
        $con = $this->_em->getConnection();

        if ( ! is_numeric($id)) {
            throw new \InvalidArgumentException(sprintf('$id must be numeric, but got "%s".', $id));
        }

        $parentId = $id;
        while (false !== $newParentId = $con->query("SELECT t.workflowExecution_id
                                                     FROM workflow_tasks t
                                                     INNER JOIN workflow_executions e ON e.parentWorkflowExecutionTask_id = t.id
                                                     WHERE e.id = ".$parentId)
                                            ->fetchColumn()) {
            $parentId = $newParentId;
        }

        // Acquire a write lock for the parent execution.
        $con->executeQuery("SELECT id FROM workflow_executions WHERE id = :id ".$con->getDatabasePlatform()->getWriteLockSQL(), array(
            'id' => $parentId,
        ));

        // Load the actually requested workflow execution which might be different from the one that we locked above.
        return $this->getById($id);
    }

    public function getById($id)
    {
        $execution = $this->findOneBy(array('id' => $id));
        if (null === $execution) {
            throw new \RuntimeException(sprintf('There is no workflow execution with id "%d".', $id));
        }

        return $execution;
    }
}