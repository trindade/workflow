<?php

namespace Scrutinizer\Workflow\RabbitMq\Transport;

use JMS\Serializer\Annotation as Serializer;

class ListWorkflowExecutions
{
    const ORDER_DESC = 'desc';
    const ORDER_ASC = 'asc';

    /** @Serializer\Type("string") */
    public $status;

    /** @Serializer\Type("array<string>") */
    public $tags = array();

    /** @Serializer\Type("array<string>") */
    public $workflows = array();

    /** @Serializer\Type("string") */
    public $order = self::ORDER_ASC;

    /** @Serializer\Type("integer") */
    public $perPage = 20;

    /** @Serializer\Type("integer") */
    public $page = 1;
}