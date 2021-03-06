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

namespace Scrutinizer\Workflow\Logger;

use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;

class OutputLogger extends AbstractLogger
{
    private $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function log($level, $message, array $context = array())
    {
        $map = array();
        foreach ($context as $k => $v) {
            $map['{'.$k.'}'] = $v;
        }

        $this->output->writeln(strtr($message, $map));

        if (isset($context['exception']) && $context['exception'] instanceof \Exception) {
            // Not sure what the render method does on Application, it should be its own class ideally...
            (new Application())->renderException($context['exception'], $this->output);
        }
    }
}