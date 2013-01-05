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

class ActivityResult
{
    const STATUS_FAILURE = 'failure';
    const STATUS_SUCCESS = 'success';

    /** @Serializer\Type("string") */
    public $taskId;

    /** @Serializer\Type("string") */
    public $status;

    /** @Serializer\Type("string") */
    public $failureReason;

    /** @Serializer\Type("string") */
    public $failureException;

    /** @Serializer\Type("string") */
    public $result;
}