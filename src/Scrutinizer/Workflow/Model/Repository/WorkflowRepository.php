<?php

namespace Scrutinizer\Workflow\Model\Repository;

use Doctrine\ORM\EntityRepository;
use Scrutinizer\Workflow\Model\Workflow;

class WorkflowRepository extends EntityRepository
{
    /**
     * Returns the workflow with the given name.
     *
     * @param string $name
     *
     * @return Workflow
     */
    public function getByName($name)
    {
        $workflow = $this->findOneBy(array('name' => $name));

        if (null === $workflow) {
            throw new \RuntimeException(sprintf('There is no workflow named "%s".', $name));
        }

        return $workflow;
    }
}