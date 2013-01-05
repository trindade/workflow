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

use Doctrine\Common\Persistence\ManagerRegistry;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Scrutinizer\RabbitMQ\Util\DsnUtils;
use Symfony\Component\Console\Command\Command;

abstract class AbstractCommand extends Command
{
    /** @var ManagerRegistry */
    protected $registry;

    protected $config;

    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    public function setRegistry(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    protected function getAmqpConnection()
    {
        return DsnUtils::createCon($this->config['rabbitmq']['dsn']);
    }

    protected function getLogger()
    {
        return new NullLogger();

//        $logger = new Logger('scrutinizer-workflow');
//        $logger->pushHandler(new NullHandler());

//        return $logger;
    }
}