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

class Event
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    private $id;

    /** @ORM\ManyToOne(targetEntity = "WorkflowExecution", inversedBy = "history")  */
    private $workflowExecution;

    /** @ORM\Column(type = "string", length = 50) */
    private $name;

    /** @ORM\Column(type = "json_array") */
    private $attributes = array();

    /** @ORM\Column(type = "datetime") */
    private $createdAt;

    public function __construct(WorkflowExecution $execution, $name, array $attributes = array())
    {
        $this->workflowExecution = $execution;
        $this->name = $name;
        $this->attributes = $attributes;
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

    public function getName()
    {
        return $this->name;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }
}