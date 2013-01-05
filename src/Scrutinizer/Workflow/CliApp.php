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

namespace Scrutinizer\Workflow;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Migrations\Configuration\Configuration;
use Doctrine\DBAL\Migrations\Tools\Console\Command\AbstractCommand;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Scrutinizer\Workflow\Command\ServerRunCommand;
use Scrutinizer\Workflow\Doctrine\SimpleRegistry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

class CliApp extends Application
{
    private $config;
    private $registry;

    public function __construct()
    {
        if (is_file($cfgFile = __DIR__.'/../../../config.yml')) {
            $this->config = Yaml::parse(file_get_contents($cfgFile));
        } else {
            $this->config = Yaml::parse(file_get_contents(__DIR__.'/../../../config.yml.dist'));
        }

        $this->registry = new SimpleRegistry($this->config);

        parent::__construct('scrutinizer-workflow', '0.1');
    }

    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new ServerRunCommand();

        $this->getHelperSet()->set(new EntityManagerHelper($this->registry->getManager()), 'em');

        // Add Migration commands
        $migrationConfig = new Configuration($this->registry->getConnection());
        $migrationConfig->setMigrationsDirectory(__DIR__.'/../../../res/migrations');
        $migrationConfig->setMigrationsNamespace('Scrutinizer\Workflow\Migrations');
        $migrationConfig->setMigrationsTableName('workflow_migrations');
        $migrationConfig->setName('workflow_migrations');
        foreach (new \DirectoryIterator(__DIR__.'/../../../vendor/doctrine/migrations/lib/Doctrine/DBAL/Migrations/Tools/Console/Command') as $file) {
            /** @var $file \SplFileInfo */

            if ( ! $file->isFile()) {
                continue;
            }

            $className = 'Doctrine\DBAL\Migrations\Tools\Console\Command\\'.$file->getBasename('.php');
            $ref = new \ReflectionClass($className);
            if ($ref->isAbstract()) {
                continue;
            }

            $commands[] = $command = $ref->newInstance();
            if ($command instanceof AbstractCommand) {
                $command->setMigrationConfiguration($migrationConfig);
            }
        }

        foreach ($commands as $command) {
            if ($command instanceof \Scrutinizer\Workflow\Command\AbstractCommand) {
                $command->setConfig($this->config);
                $command->setRegistry($this->registry);
            }
        }

        return $commands;
    }
}