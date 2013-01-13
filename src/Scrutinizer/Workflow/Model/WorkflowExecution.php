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

namespace Scrutinizer\Workflow\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use PhpOption\None;
use PhpOption\Option;
use PhpOption\Some;

/**
 * @ORM\Entity(repositoryClass = "Scrutinizer\Workflow\Model\Repository\WorkflowExecutionRepository")
 * @ORM\Table(name = "workflow_executions")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * @Serializer\ExclusionPolicy("ALL")
 */
class WorkflowExecution
{
    const STATE_OPEN = 'open';
    const STATE_FAILED = 'failed';
    const STATE_SUCCEEDED = 'succeeded';
    const STATE_TERMINATED = 'terminated';
    const STATE_CANCELED = 'canceled';

    private static $stateTransitionMap = array(
        self::STATE_OPEN => array(self::STATE_FAILED, self::STATE_SUCCEEDED, self::STATE_TERMINATED, self::STATE_CANCELED),
        self::STATE_FAILED => array(),
        self::STATE_SUCCEEDED => array(),
        self::STATE_TERMINATED => array(),
        self::STATE_CANCELED => array(),
    );

    /**
     * @ORM\Id
     * @ORM\Column(type = "bigint", options = {"unsigned": true})
     * @ORM\GeneratedValue(strategy = "AUTO")
     *
     * @Serializer\Groups({"Default", "Listing"})
     * @Serializer\Expose
     */
    private $id;

    /** @ORM\ManyToOne(targetEntity = "Workflow") */
    private $workflow;

    /**
     * @ORM\OneToOne(targetEntity = "WorkflowExecutionTask", inversedBy = "childWorkflowExecution")
     * @var WorkflowExecutionTask
     */
    private $parentWorkflowExecutionTask;

    /** @ORM\Column(type = "integer", options = {"unsigned": true}) */
    private $maxRuntime;

    /**
     * @ORM\Column(type = "text")
     * @Serializer\Expose
     * @Serializer\Groups({"Default", "Listing"})
     */
    private $input;

    /**
     * @ORM\Column(type = "string", length = 15)
     * @Serializer\Expose
     * @Serializer\Groups({"Default", "Listing"})
     */
    private $state = self::STATE_OPEN;

    /**
     * @ORM\ManyToMany(targetEntity = "Tag")
     * @ORM\JoinTable(name="workflow_execution_tags",
     *     joinColumns = { @ORM\JoinColumn(name="execution_id", referencedColumnName="id") },
     *     inverseJoinColumns = { @ORM\JoinColumn(name="tag_id", referencedColumnName="id") }
     * )
     */
    private $tags;

    /**
     * @ORM\Column(type = "datetime")
     *
     * @Serializer\Expose
     * @Serializer\Groups({"Default", "Listing"})
     */
    private $createdAt;

    /**
     * @ORM\Column(type = "datetime", nullable = true)
     */
    private $finishedAt;

    /**
     * @ORM\OneToMany(targetEntity = "AbstractTask", mappedBy = "workflowExecution", cascade = {"persist", "refresh"}, fetch = "EAGER")
     * @ORM\OrderBy({"id" = "ASC"})
     *
     * @Serializer\Groups({"Details"})
     * @Serializer\Expose
     */
    private $tasks;

    /** @ORM\Column(type = "string", nullable = true) */
    private $failureReason;

    /** @ORM\Column(type = "json_array", nullable = true) */
    private $failureDetails = array();

    /** @ORM\Column(type = "json_array", nullable = true) */
    private $cancelDetails = array();

    /** @ORM\Column(type = "boolean") */
    private $pendingDecisionTask = false;

    /**
     * @ORM\OneToMany(targetEntity = "Event", mappedBy = "workflowExecution", cascade = {"refresh"}, fetch = "EAGER")
     * @ORM\OrderBy({"id" = "ASC"})
     *
     * @Serializer\Groups({"Details"})
     * @Serializer\Expose
     */
    private $history;

    public function __construct(Workflow $workflow, $input, $maxRuntime = 3600, array $tags = array())
    {
        $this->workflow = $workflow;
        $this->input = $input;
        $this->maxRuntime = $maxRuntime;
        $this->tags = new ArrayCollection($tags);
        $this->createdAt = new \DateTime();
        $this->tasks = new ArrayCollection();
        $this->history = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getWorkflow()
    {
        return $this->workflow;
    }

    public function getHistory()
    {
        return $this->history;
    }

    /**
     * @Serializer\VirtualProperty
     * @Serializer\Groups({"Default", "Listing"})
     *
     * @return string
     */
    public function getWorkflowName()
    {
        return $this->workflow->getName();
    }

    public function setParentWorkflowExecutionTask(WorkflowExecutionTask $task)
    {
        $this->parentWorkflowExecutionTask = $task;
    }

    /**
     * @return WorkflowExecutionTask|null
     */
    public function getParentWorkflowExecutionTask()
    {
        return $this->parentWorkflowExecutionTask;
    }

    public function getMaxRuntime()
    {
        return $this->maxRuntime;
    }

    public function getInput()
    {
        return $this->input;
    }

    public function getState()
    {
        return $this->state;
    }

    private function setState($state)
    {
        if ($this->isClosed()) {
            throw new \RuntimeException(sprintf('%s is closed and cannot transition to "%s".', $this, $state));
        }

        if ( ! in_array($state, self::$stateTransitionMap[$this->state], true)) {
            throw new \InvalidArgumentException(sprintf('You cannot transition %s from "%s" to "%s".', $this, $this->state, $state));
        }

        if (empty(self::$stateTransitionMap[$state])) {
            $this->finishedAt = new \DateTime();

            if (null !== $this->parentWorkflowExecutionTask) {
                $this->parentWorkflowExecutionTask->setFinished();
            }
        }

        $this->state = $state;
    }

    public function getTags()
    {
        return $this->tags->map(function(Tag $tag) { return $tag->getName(); });
    }

    public function getActivityTaskWithId($id)
    {
        $id = (string) $id;
        foreach ($this->tasks as $task) {
            if ($task instanceof ActivityTask && $task->getId() === $id) {
                return new Some($task);
            }
        }

        return None::create();
    }

    public function isOpen()
    {
        return ! empty(self::$stateTransitionMap[$this->state]);
    }

    public function isClosed()
    {
        return empty(self::$stateTransitionMap[$this->state]);
    }

    public function hasSucceeded()
    {
        return self::STATE_SUCCEEDED === $this->state;
    }

    public function isLastDecision()
    {
        return ! $this->hasOpenActivities() && ! $this->pendingDecisionTask;
    }

    public function hasOpenActivities()
    {
        foreach ($this->tasks as $task) {
            if ( ! $task instanceof AbstractActivityTask) {
                continue;
            }

            if ($task->isOpen()) {
                return true;
            }
        }

        return false;
    }

    public function getTasks()
    {
        return $this->tasks;
    }

    public function hasOpenTasks()
    {
        foreach ($this->tasks as $task) {
            if ($task->isOpen()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Option<DecisionTask>
     */
    public function getOpenDecisionTask()
    {
        foreach ($this->tasks as $task) {
            if ($task instanceof DecisionTask && $task->isOpen()) {
                return new Some($task);
            }
        }

        return None::create();
    }

    public function createDecisionTaskIfPending()
    {
        // If new activity results arrived between scheduling the last decision task and retrieving the decision
        // result, we need to dispatch a new decision task for these new results.
        if ( ! $this->isOpen()) {
            return null;
        }

        if ( ! $this->pendingDecisionTask) {
            return null;
        }

        $this->pendingDecisionTask = false;

        return $this->createDecisionTask();
    }

    public function scheduleDecisionTask()
    {
        // Check if we need to schedule a new decision task. Generally, we always schedule a new decision task.
        // If there is already a decision task in progress, we cannot directly schedule another one, but we will
        // instead record that new activity has become available, and process it as soon as the currently
        // running decision task finishes. In case, that the workflow execution was terminated, we will not
        // schedule any new tasks, but just allow currently running activity tasks to report their results.
        if ( ! $this->isOpen()) {
            return null;
        }

        if ($this->getOpenDecisionTask()->isDefined()) {
            $this->pendingDecisionTask = true;

            return null;
        }

        return $this->createDecisionTask();
    }

    private function createDecisionTask()
    {
        if ($this->getOpenDecisionTask()->isDefined()) {
            throw new \LogicException(sprintf('There can only be one open decision task at a time for %s.', $this));
        }

        $task = new DecisionTask($this);
        $this->tasks->add($task);

        return $task;
    }

    /**
     * @param ActivityType $activity
     * @param string $input
     * @param array $controlData
     *
     * @return ActivityTask
     */
    public function createActivityTask(ActivityType $activity, $input, array $controlData = array())
    {
        $task = new ActivityTask($this, $activity, $input, $controlData);
        $this->tasks->add($task);

        return $task;
    }

    public function createWorkflowExecutionTask(WorkflowExecution $childExecution, array $controlData = array())
    {
        $task = new WorkflowExecutionTask($this, $childExecution, $controlData);
        $this->tasks->add($task);

        return $task;
    }

    public function setFailed($reason, array $details = array())
    {
        $this->setState(self::STATE_FAILED);
        $this->failureReason = $reason;
        $this->failureDetails = $details;
    }

    public function setSucceeded()
    {
        $this->setState(self::STATE_SUCCEEDED);
    }

    public function setCanceled(array $details = array())
    {
        $this->setState(self::STATE_CANCELED);
        $this->cancelDetails = $details;
    }

    public function setTerminated()
    {
        $this->setState(self::STATE_TERMINATED);
    }

    public function getFailureReason()
    {
        return $this->failureReason;
    }

    public function getFailureDetails()
    {
        return $this->failureDetails;
    }

    public function getCancelDetails()
    {
        return $this->cancelDetails;
    }

    public function __toString()
    {
        return sprintf('WorkflowExecution(id=%d)', $this->id);
    }
}