<?php

namespace Scrutinizer\Workflow\Server\Controller;

use Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType;
use Scrutinizer\Workflow\Server\AbstractController;
use Scrutinizer\Workflow\Server\Request;
use Scrutinizer\Workflow\Server\Response;
use Scrutinizer\Workflow\Server\Annotation as Controller;

class WorkflowTypeController extends AbstractController
{
    /**
     * @Controller\ActionName("declare_workflow_type")
     */
    public function declareAction(Request $request, Response $response)
    {
        /** @var $createWorkflow CreateWorkflowType */
        $createWorkflow = $this->deserialize($request->payload, 'Scrutinizer\Workflow\RabbitMq\Transport\CreateWorkflowType');

        /** @var $workflow Workflow */
        $workflow = $this->getWorkflowTypeRepo()->findOneBy(array('name' => $createWorkflow->name));
        if (null === $workflow) {
            $workflow = new Workflow($createWorkflow->name, $createWorkflow->deciderQueueName);

            $em = $this->getEntityManager();
            $em->persist($workflow);
            $em->flush($workflow);
        } else {
            if ($workflow->getDeciderQueueName() !== $createWorkflow->deciderQueueName) {
                throw new \RuntimeException(sprintf('The workflow "%s" is already declared with the decider queue "%s".', $workflow->getDeciderQueueName()));
            }
        }
    }
}