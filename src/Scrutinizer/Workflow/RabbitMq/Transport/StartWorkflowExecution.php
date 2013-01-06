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

class StartWorkflowExecution
{
    /** @Serializer\Type("string") */
    public $workflow;

    /** @Serializer\Type("string") */
    public $input;

    /** @Serializer\Type("integer") */
    public $maxRuntime = 3600;

    /** @Serializer\Type("array<string>") */
    public $tags = array();

    public function __construct($workflowName, $input, $maxRuntime = 3600, array $tags = array())
    {
        $this->workflow = $workflowName;
        $this->input = $input;
        $this->maxRuntime = $maxRuntime;
        $this->tags = $tags;
    }
}