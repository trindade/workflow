<?php

namespace Scrutinizer\Workflow\Model;

abstract class ChildPolicy
{
    const TERMINATE = 'terminate';
    const ABANDON = 'abandon';
    const REQUEST_CANCEL = 'request_cancel';

    private static $instances = array();

    private $name;

    public static function factory($name)
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        switch ($name) {
            case self::TERMINATE:
                $instance = new TerminateChildPolicy($name);
                break;

            case self::ABANDON:
                $instance = new AbandonChildPolicy($name);
                break;

            case self::REQUEST_CANCEL:
                $instance = new RequestCancelChildPolicy($name);
                break;

            default:
                throw new \LogicException(sprintf('The child policy "%s" does not exist.', $name));
        }
    }

    abstract public function apply(WorkflowExecution $childExecution);

    protected function __construct($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }
}

class TerminateChildPolicy extends ChildPolicy
{
    public function apply(WorkflowExecution $childPolicy)
    {
        // TODO
    }
}

class AbandonChildPolicy extends ChildPolicy
{
    public function apply(WorkflowExecution $childPolicy)
    {
        // Just do nothing.
    }
}

class RequestCancelChildPolicy extends ChildPolicy
{
    public function apply(WorkflowExecution $childPolicy)
    {

    }
}