<?php

namespace Scrutinizer\Workflow\Server\Annotation;

/**
 * @Annotation
 * @Target({"METHOD"})
 */
final class ActionName
{
    /** @Required @var string */
    public $name;
}