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

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;
use PhpOption\None;
use PhpOption\Some;

class Decision
{
    const TYPE_EXECUTION_FAILED = 'execution_failed';
    const TYPE_EXECUTION_SUCCEEDED = 'execution_succeeded';
    const TYPE_SCHEDULE_ACTIVITY = 'schedule_activity';
    const TYPE_SCHEDULE_CHILD_WORKFLOW = 'schedule_child_workflow';
    const TYPE_EXECUTION_CANCELED = 'execution_canceled';
    const TYPE_ADOPT_EXECUTION = 'adopt_execution';

    /** @Serializer\Type("string") */
    public $type;

    /** @Serializer\Type("array") */
    public $attributes = array();

    public function getControl()
    {
        return isset($this->attributes['control']) ? $this->attributes['control'] : array();
    }

    public function getAttribute($key)
    {
        if (isset($this->attributes[$key])) {
            return new Some($this->attributes[$key]);
        }

        return None::create();
    }

    public function getInput()
    {
        return $this->attributes['input'];
    }
}