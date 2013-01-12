<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class TerminateWorkflowExecution
{
    /** @Serializer\Type("string") */
    public $executionId;
}