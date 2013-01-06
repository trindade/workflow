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
 * @ORM\Table(name = "workflows")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 */
class Workflow
{
    /** @ORM\Column(type = "integer", options = {"unsigned": true}) @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") */
    private $id;

    /** @ORM\Column(type = "string", unique = true) */
    private $name;

    /** @ORM\Column(type = "string", length = 30) */
    private $deciderQueueName;

    public function __construct($name, $deciderQueueName)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('$name cannot be empty.');
        }
        if (empty($deciderQueueName)) {
            throw new \InvalidArgumentException('$deciderQueueName cannot be empty.');
        }

        $this->name = $name;

        if ( ! preg_match('/^[a-z_\.]+$/', $deciderQueueName)) {
            throw new \InvalidArgumentException(sprintf('The queue name "%s" is invalid. It must only consist of "a-z_.".', $deciderQueueName));
        }
        $this->deciderQueueName = $deciderQueueName;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDeciderQueueName()
    {
        return $this->deciderQueueName;
    }

    public function setName($name)
    {
        $this->name = $name;
    }
}