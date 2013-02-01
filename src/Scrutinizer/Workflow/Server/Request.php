<?php

namespace Scrutinizer\Workflow\Server;

class Request
{
    public $commandName;
    public $payload;

    public function __construct($commandName, $payload)
    {
        $this->commandName = $commandName;
        $this->payload = $payload;
    }
}