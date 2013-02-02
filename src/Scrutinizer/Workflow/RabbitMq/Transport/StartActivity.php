<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class StartActivity
{
    /** @Serializer\Type("string") */
    public $executionId;

    /** @Serializer\Type("string") */
    public $taskId;

    /** @Serializer\Type("string") */
    public $machineIdentifier;

    /** @Serializer\Type("string") */
    public $workerIdentifier;
}