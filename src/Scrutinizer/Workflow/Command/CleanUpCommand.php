<?php

namespace Scrutinizer\Workflow\Command;

use Scrutinizer\Workflow\Model\Repository\WorkflowExecutionRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('clean-up')
            ->setDescription('Cleans up old workflow executions and all related data')
            ->addOption('retention', 'r', InputOption::VALUE_REQUIRED, 'The rentention time for all data.', '7 days')
            ->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'The maximum number of rows to delete.', 1000)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->write('Removing expired executions... ');

        /** @var $repo WorkflowExecutionRepository */
        $repo = $this->registry->getRepository('Workflow:WorkflowExecution');
        $repo->removeExpiredExecutions(
            $input->getOption('retention'),
            $input->getOption('max-rows')
        );

        $output->writeln('Done.');
    }
}