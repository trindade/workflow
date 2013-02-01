<?php

namespace Scrutinizer\Workflow\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Scrutinizer\Workflow\Model\WorkflowExecution;
use Scrutinizer\Workflow\Server\Response;

class ExecutionListener implements EventSubscriber
{
    private $container;

    public function getSubscribedEvents()
    {
        return array('preUpdate');
    }

    public function setContainer(\Pimple $container)
    {
        $this->container = $container;
    }

    public function preUpdate(PreUpdateEventArgs $event)
    {
        $execution = $event->getEntity();
        if ( ! $execution instanceof WorkflowExecution) {
            return;
        }

        if ($event->hasChangedField('state')) {
            $response = $this->getResponse();

            switch ($event->getNewValue('state')) {
                case WorkflowExecution::STATE_TERMINATED:
                    $this->onTermination($response, $execution);
            }
        }
    }

    /**
     * @return Response
     */
    protected function getResponse()
    {
        return $this->container['response'];
    }
}