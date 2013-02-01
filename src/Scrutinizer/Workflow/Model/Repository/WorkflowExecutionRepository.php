<?php

namespace Scrutinizer\Workflow\Model\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityRepository;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class WorkflowExecutionRepository extends EntityRepository
{
    /**
     * @param string[] $ids
     *
     * @return WorkflowExecution[]
     */
    public function getByIdExclusiveForAdoption($parentId, $childId)
    {
        // Currently, we are being pretty gross here by simply locking the entire table down. This is necessary to
        // preserve a consistent state when we change parents. In the future, we might want to explore more sophisticated
        // algorithms to only lock down specific trees.
        $con = $this->_em->getConnection();
        $con->executeQuery("SELECT id FROM workflow_execution_lock ".$con->getDatabasePlatform()->getWriteLockSQL());

        return array($this->getById($parentId), $this->getById($childId));
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
        // Acquire a write lock for all trees that this execution is part of.
        $con = $this->_em->getConnection();
        $con->executeQuery(
            "SELECT id FROM workflow_executions WHERE id IN (:ids) ".$con->getDatabasePlatform()->getWriteLockSQL(),
            array('ids' => $this->getTopMostParents($id)),
            array('ids' => Connection::PARAM_STR_ARRAY)
        );

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

    /**
     * Finds the tree roots from which the given id is reachable.
     *
     * We will lock all tree roots for modifying the given
     *
     * @param string $id
     *
     * @return string[]
     */
    private function getTopMostParents($id)
    {
        $con = $this->_em->getConnection();
        $topMostIds = array();

        // This ensures that there are no read operations when we are modifying parent relations in an adoption task.
        $con->executeQuery("SELECT id FROM workflow_execution_lock ".$con->getDatabasePlatform()->getReadLockSQL());

        $parentIds = array($id);
        while ($parentId = array_shift($parentIds)) {
            if (in_array($parentId, $topMostIds, true)) {
                continue;
            }

            $newParentIds = $con->executeQuery("SELECT workflowExecution_id FROM workflow_tasks WHERE childWorkflowExecution_id = :id", array(
                'id' => $parentId,
            ))->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($newParentIds)) {
                $topMostIds[] = $parentId;
                continue;
            }

            $parentIds = array_merge($parentIds, $newParentIds);
        }

        return $topMostIds;
    }
}