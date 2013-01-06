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
 * @ORM\Table(name = "workflow_tasks")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string", length = 30)
 * @ORM\DiscriminatorMap({
 *     "activity": "ActivityTask",
 *     "decision": "DecisionTask",
 *     "workflow_execution": "WorkflowExecutionTask",
 * })
 */
abstract class AbstractTask
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    private $id;

    /** @ORM\ManyToOne(targetEntity = "WorkflowExecution", inversedBy = "tasks") @Serializer\Exclude */
    private $workflowExecution;

    /** @ORM\Column(type = "datetime") */
    private $createdAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    private $finishedAt;

    public function __construct(WorkflowExecution $workflowExecution)
    {
        $this->workflowExecution = $workflowExecution;
        $this->createdAt = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getWorkflowExecution()
    {
        return $this->workflowExecution;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function getFinishedAt()
    {
        return $this->finishedAt;
    }

    protected function setFinished()
    {
        $this->finishedAt = new \DateTime();
    }

    /**
     * @Serializer\VirtualProperty
     *
     * @return string
     */
    abstract public function getType();

    abstract public function isOpen();
    abstract public function isClosed();
    abstract public function __toString();
}