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

namespace Scrutinizer\Workflow\Doctrine;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\EventManager;
use Doctrine\Common\Persistence\AbstractManagerRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\ORMException;
use Scrutinizer\Workflow\Model\Listener\EventDispatchingListener;

class SimpleRegistry extends AbstractManagerRegistry
{
    private $config;
    private $services;

    public function __construct(array $config)
    {
        parent::__construct(
            'scrutinizer',
            array('default' => 'default_connection'),
            array('default' => 'default_manager'),
            'default',
            'default',
            'Doctrine\Common\Persistence\Proxy'
        );

        $this->config = $config;
    }

    public function getService($name)
    {
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }

        switch ($name) {
            case 'default_event_manager':
                $service = new EventManager();
                break;

            case 'default_connection':
                $service = DriverManager::getConnection($this->config['database'], null, $this->getService('default_event_manager'));
                break;

            case 'default_manager':
                $cfg = new Configuration();
                $cfg->setMetadataDriverImpl(new AnnotationDriver(new AnnotationReader(), array(
                    __DIR__.'/../Model',
                )));
                $cfg->setProxyDir(sys_get_temp_dir().'/scrutinizer-workflow-proxies');
                $cfg->setAutoGenerateProxyClasses(true);
                $cfg->setProxyNamespace('Scrutinizer\Workflow\Proxies');
                $cfg->addEntityNamespace('Workflow', 'Scrutinizer\Workflow\Model');

                $service = EntityManager::create($this->getConnection(), $cfg, $this->getService('default_event_manager'));
                break;

            default:
                throw new \RuntimeException(sprintf('There is no service named "%s".', $name));
        }

        return $this->services[$name] = $service;
    }

    public function resetService($name)
    {
        unset($this->services[$name]);
    }

    public function getAliasNamespace($alias)
    {
        foreach (array_keys($this->getManagers()) as $name) {
            try {
                return $this->getManager($name)->getConfiguration()->getEntityNamespace($alias);
            } catch (ORMException $e) {
            }
        }

        throw ORMException::unknownEntityNamespace($alias);
    }
}