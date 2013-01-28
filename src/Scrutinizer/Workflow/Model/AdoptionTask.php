<?php

namespace Scrutinizer\Workflow\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Represents an adoption request from a WorkflowExecution.
 *
 * We cannot directly adopt executions as doing so might lead to dead-locks. Instead, we just create a new task of this
 * type, and schedule it like a normal task. However, instead of dispatching it to a user component, these tasks are
 * processed by the server itself. This way we can never end up in a deadlock situation as we will always acquire locks
 * for the necessary rows at once.
 *
 * @ORM\Entity
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class AdoptionTask extends AbstractActivityTask
{
    const STATE_OPEN = 'open';
    const STATE_SUCCEEDED = 'succeeded';
    const STATE_FAILED = 'failed';

    /** @ORM\Column(type = "string", length = 30) */
    private $state = self::STATE_OPEN;

    /** @ORM\ManyToOne(targetEntity = "WorkflowExecution") */
    private $childWorkflowExecution;

    /** @ORM\Column(type = "string", nullable = true) */
    private $failureReason;

    public function __construct(WorkflowExecution $parent, WorkflowExecution $child)
    {
        parent::__construct($parent);

        $this->childWorkflowExecution = $child;
    }

    public function getChildWorkflowExecution()
    {
        return $this->childWorkflowExecution;
    }

    public function getType()
    {
        return 'adoption';
    }

    public function setFailed($reason)
    {
        if ( ! $this->isOpen()) {
            throw new \LogicException(sprintf('%s is not open anymore.', $this));
        }

        $this->setFinished();
        $this->state = self::STATE_FAILED;
        $this->failureReason = $reason;
    }

    public function setSucceeded()
    {
        if ( ! $this->isOpen()) {
            throw new \LogicException(sprintf('%s is not open anymore.', $this));
        }

        $this->setFinished();
        $this->state = self::STATE_SUCCEEDED;
    }

    public function isOpen()
    {
        return self::STATE_OPEN === $this->state;
    }

    public function isClosed()
    {
        return self::STATE_OPEN !== $this->state;
    }

    public function __toString()
    {
        return sprintf('AdoptionTask(id=%d, state=%s)', $this->getId(), $this->state);
    }
}