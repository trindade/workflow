<?php

namespace Scrutinizer\Tests\Workflow\Model;

use Scrutinizer\Workflow\Model\ActivityType;
use Scrutinizer\Workflow\Model\Workflow;
use Scrutinizer\Workflow\Model\WorkflowExecution;

class WorkflowExecutionTest extends \PHPUnit_Framework_TestCase
{
    public function testDecisionTaskScheduling()
    {
        $execution = new WorkflowExecution(new Workflow('foo', 'bar'), '');

        $this->assertTrue($execution->getOpenDecisionTask()->isEmpty(), 'There is no open decision task.');
        $this->assertNotNull($execution->scheduleDecisionTask(), 'Returns a new decision task if there is no open task.');

        $this->assertNull($execution->scheduleDecisionTask(), 'Returns null if there is an open decision task.');
        $execution->getOpenDecisionTask()->get()->close();
        $this->assertNotNull($execution->createDecisionTaskIfPending(), 'Creates a decision task if pending.');
        $this->assertNull($execution->createDecisionTaskIfPending(), 'Pending state was successfully reset.');
        $execution->getOpenDecisionTask()->get()->close();

        $execution->setState(WorkflowExecution::STATE_SUCCEEDED);
        $this->assertNull($execution->scheduleDecisionTask(), 'Returns null if execution is closed.');
        $this->assertNull($execution->createDecisionTaskIfPending(), 'Returns null if execution is closed.');
    }

    public function testIsLastDecision()
    {
        $execution = new WorkflowExecution(new Workflow('foo', 'bar'), '');
        $this->assertTrue($execution->isLastDecision());

        $activityTask = $execution->createActivityTask(new ActivityType('foo', 'bar'), '');
        $this->assertFalse($execution->isLastDecision());
        $activityTask->setResult('');
        $this->assertTrue($execution->isLastDecision());

        $this->assertNotNull($execution->createWorkflowExecutionTask($childExecution = new WorkflowExecution(new Workflow('foo', 'bar'), '')));
        $this->assertFalse($execution->isLastDecision());
        $childExecution->setState(WorkflowExecution::STATE_SUCCEEDED);
        $this->assertTrue($execution->isLastDecision());

        $this->assertNotNull($execution->scheduleDecisionTask());
        $this->assertTrue($execution->isLastDecision());
        $this->assertNull($execution->scheduleDecisionTask());
        $this->assertFalse($execution->isLastDecision());
    }

    public function testGetOpenDecisionTask()
    {
        $execution = new WorkflowExecution(new Workflow('foo', 'bar'), '');
        $this->assertTrue($execution->getOpenDecisionTask()->isEmpty());

        $this->assertNotNull($decisionTask = $execution->scheduleDecisionTask());
        $this->assertFalse($execution->getOpenDecisionTask()->isEmpty());
        $this->assertSame($decisionTask, $execution->getOpenDecisionTask()->get());

        $decisionTask->close();
        $this->assertTrue($execution->getOpenDecisionTask()->isEmpty());

        $this->assertNotNull($decisionTask = $execution->scheduleDecisionTask());
        $this->assertFalse($execution->getOpenDecisionTask()->isEmpty());
        $this->assertSame($decisionTask, $execution->getOpenDecisionTask()->get());

        $this->assertNull($execution->scheduleDecisionTask());
        $decisionTask->close();
        $this->assertTrue($execution->getOpenDecisionTask()->isEmpty());

        $this->assertNotNull($decisionTask = $execution->createDecisionTaskIfPending());
        $this->assertFalse($execution->getOpenDecisionTask()->isEmpty());
        $decisionTask->close();
        $this->assertTrue($execution->getOpenDecisionTask()->isEmpty());
    }
}