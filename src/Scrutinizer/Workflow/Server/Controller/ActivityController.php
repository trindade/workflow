<?php

namespace Scrutinizer\Workflow\Server\Controller;

use Scrutinizer\Workflow\Model\ActivityTask;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult;
use Scrutinizer\Workflow\Server\AbstractController;
use Scrutinizer\Workflow\Server\Annotation as Controller;
use Scrutinizer\Workflow\Server\Request;
use Scrutinizer\Workflow\Server\Response;

class ActivityController extends AbstractController
{
    /**
     * @Controller\ActionName("report_activity_result")
     */
    public function reportResultAction(Request $request, Response $response)
    {
        /** @var $activityResult ActivityResult */
        $activityResult = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\ActivityResult');

        /** @var $execution WorkflowExecution */
        $execution = $this->getWorkflowExecutionRepo()->getByIdExclusive($activityResult->executionId);
        $response->setWorkflowExecution($execution);

        /** @var $activityTask ActivityTask */
        $activityTask = $execution->getActivityTaskWithId($activityResult->taskId)->get();

        switch ($activityResult->status) {
            case ActivityResult::STATUS_SUCCESS:
                $activityTask->setResult($activityResult->result);
                break;

            case ActivityResult::STATUS_FAILURE:
                $activityTask->setFailureDetails($activityResult->failureReason, $activityResult->failureException);
                break;

            default:
                throw new \LogicException(sprintf('Unknown activity status "%s".', $activityResult->status));
        }

        $em = $this->getEntityManager();
        $em->persist($execution);
        $em->flush();
    }
}