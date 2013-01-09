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

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;

/**
 * @ORM\Entity
 */
class ActivityTask extends AbstractActivityTask
{
    const STATE_OPEN = 'open';
    const STATE_FAILED = 'failed';
    const STATE_SUCCEEDED = 'succeeded';

    /** @ORM\ManyToOne(targetEntity = "ActivityType") @Serializer\Exclude */
    private $activityType;

    /** @ORM\Column(type = "text") */
    private $input;

    /** @ORM\Column(type = "text", nullable = true) */
    private $result;

    /** @ORM\Column(type = "string", length = 30) */
    private $state = self::STATE_OPEN;

    /** @ORM\Column(type = "string", nullable = true) */
    private $failureReason;

    /** @ORM\Column(type = "json_array", nullable = true) */
    private $failureException;

    /**
     * @param WorkflowExecution $execution
     * @param ActivityType $activityType
     * @param string $input
     * @param array $control
     */
    public function __construct(WorkflowExecution $execution, ActivityType $activityType, $input, array $control = array())
    {
        parent::__construct($execution, $control);

        $this->activityType = $activityType;
        $this->input = $input;
    }

    public function getActivityType()
    {
        return $this->activityType;
    }

    /**
     * @Serializer\VirtualProperty
     * @Serializer\Groups({"decider"})
     */
    public function getActivityName()
    {
        return $this->activityType->getName();
    }

    public function getInput()
    {
        return $this->input;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getState()
    {
        return $this->state;
    }

    public function getType()
    {
        return 'activity';
    }

    public function isOpen()
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed()
    {
        return ! $this->isOpen();
    }

    public function setFailureDetails($reason, array $exception = null)
    {
        $this->state = self::STATE_FAILED;
        $this->setFinished();
        $this->failureReason = $reason;
        $this->failureException = $exception;
    }

    public function setResult($result)
    {
        $this->state = self::STATE_SUCCEEDED;
        $this->setFinished();
        $this->result = $result;
    }

    public function __toString()
    {
        return sprintf('ActivityTask(id = %d, state = %s)', $this->getId(), $this->state);
    }
}