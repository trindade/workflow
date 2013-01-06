<?php

namespace Scrutinizer\Workflow\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\MappedSuperclass
 */
abstract class AbstractActivityTask extends AbstractTask
{
    /** @ORM\Column(type = "json_array") */
    private $control = array();

    public function __construct(WorkflowExecution $execution, array $control = array())
    {
        parent::__construct($execution);
        $this->control = $control;
    }

    public function getControl()
    {
        return $this->control;
    }
}