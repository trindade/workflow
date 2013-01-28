<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class AdoptionRequest
{
    /** @Serializer\Type("string") */
    public $childExecutionId;

    /** @Serializer\Type("string") */
    public $parentExecutionId;

    /** @Serializer\Type("string") */
    public $taskId;
}