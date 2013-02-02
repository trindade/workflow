<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class StartActvity
{
    /** @Serializer\Type("string") */
    public $executionId;

    /** @Serializer\Type("string") */
    public $taskId;

    /** @Serializer\Type("string") */
    public $machineIdentfier;

    /** @Serializer\Type("string") */
    public $workerIdentifier;
}