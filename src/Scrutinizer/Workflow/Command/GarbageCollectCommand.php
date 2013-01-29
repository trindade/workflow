<?php

namespace Scrutinizer\Workflow\Command;

use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\SerializerBuilder;
use Scrutinizer\Workflow\RabbitMq\WorkflowServerWorker;
use Scrutinizer\Workflow\Serializer\ChildWorkflowExecutionHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GarbageCollectCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('garbage:collect')
            ->setDescription('Garbage collects timed-out tasks, and executions.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $server = new WorkflowServerWorker(
            $this->getAmqpConnection(),
            $this->registry,
            $this->getSerializer()
        );
        $server->collectGarbage();
    }
}