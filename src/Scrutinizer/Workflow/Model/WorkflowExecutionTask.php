<?php

namespace Scrutinizer\Workflow\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class WorkflowExecutionTask extends AbstractActivityTask
{
    /**
     * @ORM\OneToOne(targetEntity = "WorkflowExecution", mappedBy="parentWorkflowExecutionTask", fetch = "EAGER", cascade = {"refresh", "persist"})
     */
    private $childWorkflowExecution;

    public function __construct(WorkflowExecution $execution, WorkflowExecution $childExecution, array $control = array())
    {
        parent::__construct($execution, $control);

        $this->childWorkflowExecution = $childExecution;
        $this->childWorkflowExecution->setParentWorkflowExecutionTask($this);
    }

    public function getChildWorkflowExecution()
    {
        return $this->childWorkflowExecution;
    }

    public function isOpen()
    {
        return $this->childWorkflowExecution->isOpen();
    }

    public function isClosed()
    {
        return $this->childWorkflowExecution->isClosed();
    }

    public function getType()
    {
        return 'workflow_execution';
    }

    public function setFinished()
    {
        parent::setFinished();
    }

    public function __toString()
    {
        return sprintf('WorkflowExecutionTask(id = %d, state = %s)', $this->getId(), $this->isOpen() ? 'open' : 'closed');
    }
}