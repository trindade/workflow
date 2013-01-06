<?php

/*
 * Copyright 2013 Johannes M. Schmitt <schmittjoh@gmail.com>
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Scrutinizer\Workflow\Command;

use JMS\Serializer\SerializerBuilder;
use Scrutinizer\RabbitMQ\Util\DsnUtils;
use Scrutinizer\Workflow\Logger\FanoutLogger;
use Scrutinizer\Workflow\Logger\OutputLogger;
use Scrutinizer\Workflow\RabbitMq\WorkflowServerWorker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServerRunCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('server:run')
            ->setDescription('Runs the workflow server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = $this->getLogger();
        if ($input->getOption('verbose')) {
            $logger = new FanoutLogger(array(
                new OutputLogger($output),
                $logger,
            ));
        }

        $server = new WorkflowServerWorker($this->getAmqpConnection(), $this->registry, SerializerBuilder::create()->build(), $logger);
        $server->run();
    }
}