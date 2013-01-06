<?php

namespace Scrutinizer\Workflow\Model\Repository;

use Doctrine\ORM\EntityRepository;
use Scrutinizer\Workflow\Model\ActivityType;

class ActivityTypeRepository extends EntityRepository
{
    /**
     * @param string $name
     *
     * @return ActivityType
     */
    public function getByName($name)
    {
        $activityType = $this->findOneBy(array('name' => $name));
        if (null === $activityType) {
            throw new \RuntimeException(sprintf('The activity "%s" does not exist.', $name));
        }

        return $activityType;
    }
}