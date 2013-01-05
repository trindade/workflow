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

class LogEntry
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    private $id;

    /** @ORM\Column(type = "text") */
    private $message;

    /** @ORM\Column(type = "json_array") */
    private $context;

    /** @ORM\ManyToOne(targetEntity = "WorkflowExecution") */
    private $workflowExecution;

    /** @ORM\ManyToOne(targetEntity = "AbstractTask") */
    private $task;

    /** @ORM\Column(type = "datetime") */
    private $createdAt;

    public function __construct(WorkflowExecution $execution, $message, array $context = array(), AbstractTask $task = null)
    {
        $this->message = $message;
        $this->context = $context;
        $this->workflowExecution = $execution;
        $this->task = $task;
        $this->createdAt = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getWorkflowExecution()
    {
        return $this->workflowExecution;
    }

    public function getTask()
    {
        return $this->task;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }
}