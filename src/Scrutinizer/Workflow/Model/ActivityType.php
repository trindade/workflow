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

/**
 * @ORM\Entity
 * @ORM\Table(name = "workflow_activity_types")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class ActivityType
{
    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "integer", options = {"unsigned": true}) */
    private $id;

    /** @ORM\Column(type = "string", length = 50, unique = true) */
    private $name;

    /** @ORM\Column(type = "string", length = 50) */
    private $queueName;

    public function __construct($name, $queueName)
    {
        $this->name = $name;
        $this->queueName = $queueName;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getQueueName()
    {
        return $this->queueName;
    }

    public function setQueueName($name)
    {
        $this->queueName = $name;
    }
}