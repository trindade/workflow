<?php

namespace Scrutinizer\Workflow\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

class LockListener implements EventSubscriber
{
    public function getSubscribedEvents()
    {
        return array('postGenerateSchema');
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event)
    {
        $schema = $event->getSchema();
        $table = $schema->createTable('workflow_execution_lock');
        $table->addColumn('id', 'smallint');
        $table->setPrimaryKey(array('id'));
    }
}