<?php

namespace Scrutinizer\Workflow\Server;

class Message
{
    public $body;
    public $properties;

    public function __construct($body, array $properties)
    {
        $this->body = $body;
        $this->properties = $properties;
    }
}