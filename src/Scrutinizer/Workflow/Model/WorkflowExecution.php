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
 * @ORM\Entity
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

    private static $stateTransitionMap = array(
        self::STATE_OPEN => array(self::STATE_FAILED, self::STATE_SUCCEEDED),
        self::STATE_FAILED => array(),
        self::STATE_SUCCEEDED => array(),
    );

    /**
     * @ORM\Id
     * @ORM\Column(type = "bigint", options = {"unsigned": true})
     * @ORM\GeneratedValue(strategy = "AUTO")
     *
     * @Serializer\Expose
     */
    private $id;

    /** @ORM\ManyToOne(targetEntity = "Workflow") */
    private $workflow;

    /** @ORM\Column(type = "integer", options = {"unsigned": true}) */
    private $maxRuntime;

    /** @ORM\Column(type = "text") @Serializer\Expose */
    private $input;

    /** @ORM\Column(type = "string", length = 15) */
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
     * @Serializer\Expose
     */
    private $tasks;

    /** @ORM\Column(type = "string", nullable = true) */
    private $failureReason;

    /** @ORM\Column(type = "boolean") */
    private $pendingDecisionTask = false;

    /**
     * @ORM\OneToMany(targetEntity = "Event", mappedBy = "workflowExecution", cascade = {"refresh"}, fetch = "EAGER")
     * @ORM\OrderBy({"id" = "ASC"})
     *
     * @Serializer\Expose
     */
    private $history;

    public function __construct(Workflow $workflow, $input, $maxRuntime = 3600, array $tags)
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

    public function setState($state)
    {
        if ($this->isClosed()) {
            throw new \RuntimeException(sprintf('%s is closed and cannot transition to "%s".', $this, $state));
        }

        if ( ! in_array($state, self::$stateTransitionMap[$this->state], true)) {
            throw new \InvalidArgumentException(sprintf('You cannot transition %s from "%s" to "%s".', $this, $this->state, $state));
        }

        if (empty(self::$stateTransitionMap[$state])) {
            $this->finishedAt = new \DateTime();
        }

        $this->state = $state;
    }

    public function getTags()
    {
        return $this->tags->map(function(Tag $tag) { return $tag->getName(); });
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

    public function hasOpenActivities()
    {
        foreach ($this->tasks as $task) {
            if ( ! $task instanceof ActivityTask) {
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

    public function hasPendingDecisionTask()
    {
        return $this->pendingDecisionTask;
    }

    public function setPendingDecisionTask($bool)
    {
        $this->pendingDecisionTask = (boolean) $bool;
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

    public function createDecisionTask()
    {
        if ($this->getOpenDecisionTask()->isDefined()) {
            throw new \LogicException(sprintf('There can only be one open decision task at a time for %s.', $this));
        }

        $lastTask = null;
        foreach (array_reverse($this->tasks->toArray()) as $task) {
            if ($task instanceof ActivityTask && ! $task->isOpen()) {
                $lastTask = $task;
                break;
            }
        }

        $task = new DecisionTask($this, $lastTask);
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

    public function setFailureReason($reason)
    {
        $this->failureReason = $reason;
    }

    public function getFailureReason()
    {
        return $this->failureReason;
    }

    public function __toString()
    {
        return sprintf('WorkflowExecution(id=%d)', $this->id);
    }
}