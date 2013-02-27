<?php

namespace Scrutinizer\Workflow\Serializer;

use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\JsonSerializationVisitor;
use JMS\Serializer\NavigatorContext;
use JMS\Serializer\SerializationContext;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class ChildWorkflowExecutionHandler implements SubscribingHandlerInterface
{
    private $depth = 0;

    public static function getSubscribingMethods()
    {
        return array(
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'type' => 'ChildWorkflowExecution',
                'format' => 'json',
                'method' => 'serializeToJson',
            )
        );
    }

    public function serializeToJson(JsonSerializationVisitor $visitor, WorkflowExecution $execution, array $type, SerializationContext $context)
    {
        if ($this->depth > 0) {
            return array(
                'id' => (string) $execution->getId(),
                'state' => $execution->getState(),
                'workflow_name' => $execution->getWorkflowName(),
                'input' => $execution->getInput(),
            );
        }

        $this->depth += 1;
        $context->stopVisiting($execution);
        $rs = $context->accept($execution, null);
        $context->startVisiting($execution);
        $this->depth -= 1;

        return $rs;
    }
}