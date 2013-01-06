<?php

namespace Scrutinizer\Tests\Workflow\Model;

use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\Model\WorkflowExecutionTask;

class WorkflowExecutionTaskTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider getBooleanValues
     */
    public function testIsOpen($bool)
    {
        $execution = $this->getExecutionBuilder()->getMock();
        $execution->expects($this->once())
            ->method('isOpen')
            ->will($this->returnValue($bool));

        $task = new WorkflowExecutionTask($this->getExecutionBuilder()->getMock(), $execution);
        $this->assertSame($bool, $task->isOpen());
    }

    /**
     * @dataProvider getBooleanValues
     */
    public function testIsClosed($bool)
    {
        $execution = $this->getExecutionBuilder()->getMock();
        $execution->expects($this->once())
            ->method('isClosed')
            ->will($this->returnValue($bool));

        $task = new WorkflowExecutionTask($this->getExecutionBuilder()->getMock(), $execution);
        $this->assertSame($bool, $task->isClosed());
    }

    public function getBooleanValues()
    {
        return array(array(true), array(false));
    }

    private function getExecutionBuilder()
    {
        return $this->getMockBuilder('Scrutinizer\Workflow\Model\WorkflowExecution')
                    ->disableOriginalConstructor();
    }
}