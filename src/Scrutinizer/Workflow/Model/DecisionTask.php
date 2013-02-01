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
class DecisionTask extends AbstractTask
{
    const STATE_OPEN = 'open';
    const STATE_CLOSED = 'closed';

    /** @ORM\Column(type = "string", length = 30) */
    private $state = self::STATE_OPEN;

    public function close($nbDecisions)
    {
        $this->state = self::STATE_CLOSED;
        $this->setFinished();

        $this->getWorkflowExecution()->addEvent('execution.new_decision', array(
            'nb_decisions' => $nbDecisions,
            'task_id' => (string) $this->getId()
        ));
    }

    public function isOpen()
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed()
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function getState()
    {
        return $this->state;
    }

    public function getType()
    {
        return 'decision';
    }

    public function __toString()
    {
        return sprintf('DecisionTask(id = %d, state = %s)', $this->getId(), $this->state);
    }
}