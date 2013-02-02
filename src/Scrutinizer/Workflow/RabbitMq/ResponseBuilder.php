<?php

namespace Scrutinizer\Workflow\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class ResponseBuilder
{
    private $messages = array();
    private $responseData = array();
    private $workflowExecution;
    private $serializerGroups = array();

    public function queueMessage(AMQPMessage $message, $exchange, $routingKey)
    {
        $this->messages[] = array($message, $exchange, $routingKey);

        return $this;
    }

    public function getMessages()
    {
        return $this->messages;
    }

    public function setSerializerGroups(array $groups)
    {
        $this->serializerGroups = $groups;

        return $this;
    }

    public function getSerializerGroups()
    {
        return $this->serializerGroups;
    }

    public function setResponseData($data)
    {
        $this->responseData = $data;

        return $this;
    }

    public function getResponseData()
    {
        return $this->responseData;
    }

    public function setWorkflowExecution(WorkflowExecution $execution)
    {
        $this->workflowExecution = $execution;

        return $this;
    }

    public function getWorkflowExecution()
    {
        return $this->workflowExecution;
    }
}