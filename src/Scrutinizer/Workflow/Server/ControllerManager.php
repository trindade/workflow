<?php

namespace Scrutinizer\Workflow\Server;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class ControllerManager
{
    private $controllers = array();
    private $annotationReader;

    public function __construct(Reader $reader = null)
    {
        $this->annotationReader = $reader ?: new AnnotationReader();

        foreach (Finder::create()->in(__DIR__.'/Controller/')->files()->name('*.php') as $file) {
            /** @var $file SplFileInfo */

            $className = 'Scrutinizer\Workflow\Server\Controller\\'.substr($file->getRelativePathname(), 0, -4);

            /** @var $controller AbstractController */
            $controller = new $className;

            $ref = new \ReflectonClass($className);
            foreach ($ref->getMethods() as $method) {
                if ( ! $method->isPublic()) {
                    continue;
                }

                /** @var $actionName Annotation\ActionName */
                $actionName = $this->annotationReader->getMethodAnnotation($method, 'Scrutinizer\Workflow\Server\Annotation\ActionName');
                if (null !== $actionName) {
                    $this->controllers[$actionName->name] = array($controller, $method->name);
                }
            }
        }
    }

    public function resolveController($name)
    {
        if ( ! isset($this->controllers[$name])) {
            throw new \InvalidArgumentException(sprintf('There is no controller named "%s".', $name));
        }

        return $this->controllers[$name];
    }
}