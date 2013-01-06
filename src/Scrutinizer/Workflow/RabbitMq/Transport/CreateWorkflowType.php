<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class CreateWorkflowType
{
    /** @Serializer\Type("string") */
    public $name;

    /** @Serializer\Type("string") */
    public $deciderQueueName;
}