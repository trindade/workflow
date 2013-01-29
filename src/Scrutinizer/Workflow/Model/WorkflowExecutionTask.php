<?php

namespace Scrutinizer\Workflow\Model;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;

/**
 * @ORM\Entity
 */
class WorkflowExecutionTask extends AbstractActivityTask
{
    /**
     * @Serializer\Type("ChildWorkflowExecution")
     * @ORM\ManyToOne(targetEntity = "WorkflowExecution", inversedBy="parentWorkflowExecutionTasks", fetch = "EAGER", cascade = {"refresh", "persist"})
     */
    private $childWorkflowExecution;

    /** @ORM\Column(type = "string", length = 20) */
    private $childPolicy;

    public function __construct(WorkflowExecution $execution, WorkflowExecution $childExecution, array $control = array(), $childPolicy = Workflow::CHILD_POLICY_ABANDON)
    {
        parent::__construct($execution, $control);

        $this->childWorkflowExecution = $childExecution;
        $this->childWorkflowExecution->addParentWorkflowExecutionTask($this);
        $this->childPolicy = $childPolicy;
    }

    public function getChildPolicy()
    {
        return $this->childPolicy;
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