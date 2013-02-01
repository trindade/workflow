<?php

namespace Scrutinizer\Workflow\Server;

use Doctrine\ORM\EntityManager;
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use Scrutinizer\Workflow\Model\Repository\TagRepository;
use Scrutinizer\Workflow\Model\Repository\WorkflowExecutionRepository;
use Scrutinizer\Workflow\Model\Repository\WorkflowRepository;

abstract class AbstractController
{
    private $container;

    public function setContainer(\Pimple $container)
    {
        $this->container = $container;
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        return $this->container['registry']->getManager();
    }

    /**
     * @return WorkflowRepository
     */
    protected function getWorkflowTypeRepo()
    {
        return $this->getEntityManager()->getRepository('Workflow:Workflow');
    }

    /**
     * @return WorkflowExecutionRepository
     */
    protected function getWorkflowExecutionRepo()
    {
        return $this->getEntityManager()->getRepository('Workflow:WorkflowExecution');
    }

    /**
     * @return TagRepository
     */
    protected function getTagRepo()
    {
        return $this->getEntityManager()->getRepository('Workflow:Tag');
    }

    protected function serialize($data, array $groups = array())
    {
        $this->container['serializer']->setExclusionStrategy(empty($groups) ? null : new GroupsExclusionStrategy($groups));

        return $this->container['serializer']->serialize($data, 'json');
    }

    protected function deserialize($data, $type)
    {
        $this->container['serializer']->setExclusionStrategy(null);

        return $this->container['serializer']->deserialize($data, $type, 'json');
    }
}