<?php

namespace Scrutinizer\Workflow\Serializer;

use JMS\Serializer\GraphNavigator;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\JsonSerializationVisitor;
use JMS\Serializer\NavigatorContext;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class ChildWorkflowExecutionHandler implements SubscribingHandlerInterface
{
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

    public function serializeToJson(JsonSerializationVisitor $visitor, WorkflowExecution $execution, array $type, NavigatorContext $context)
    {
        if ($context->getDepth() > 10) {
            return array(
                'id' => (string) $execution->getId(),
                'state' => $execution->getState(),
            );
        }

        // By default, we re-visit this object with its real class name instead of the faked out handler type name.
        $context->stopVisiting($execution);
        $rs = $visitor->getNavigator()->accept($execution, null, $visitor);
        $context->startVisiting($execution);

        return $rs;
    }
}