<?php

if ( ! is_file($autoloadFile = __DIR__.'/../vendor/autoload.php')) {
    echo 'Could not find "vendor/autoload.php". Did you run "composer install --dev"?'.PHP_EOL;
    exit(1);
}

$loader = require $autoloadFile;
$loader->add('Scrutinizer', __DIR__);

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader('class_exists');

if (is_file($cfgFile = __DIR__.'/../config.yml')) {
    $_SERVER['CONFIG'] = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($cfgFile));
} else {
    $_SERVER['CONFIG'] = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/../config.yml.dist'));
}

if ($_SERVER['CONFIG']['env'] !== 'test') {
    echo 'Tests can only be run in "test" environment.'.PHP_EOL;
    echo 'Please keep in mind that running in test environment, may disrupt the state of the database.'.PHP_EOL;
    exit(1);
}