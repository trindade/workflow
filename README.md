# Scrutinizer Workflow

Scrutinizer Workflow is a simple framework for implementing distributed workflows. Each workflow may consist of
different tasks which are potentially run on different physical machines in different places around the world, and
in different programming languages.

Features:

- Distributed Tasks
- Flexible Control Flow (including parallel, or sequential execution, dependencies, retry logic, etc.)
- Language Agnostic
- Tracking of Execution State
- Versioning of Workflows (not yet implemented)
- Child Workflows (not yet implemented)
- Signaling of Tasks (not yet implemented)


## Installation

After downloading the library, you need to install its vendors using [composer](https://getcomposer.org):

```
composer install
```

Then, you can use the included CLI interface for setting up the persistence database, and launching workflow server
workers.

```
./bin/scrutinizer-workflow
```

As RabbitMQ is used as a means for communication, you need to have a RabbitMQ server installed.


## Configuration

For configuration, simply copy ``config.yml.dist`` to ``config.yml`` and fill in the details.


## Usage

You can take a look at the [scrutinizer-client library](https://github.com/scrutinizer-ci/workflow-php-client) for a
reference implementation in PHP, but in general you can use any other programming language by publishing messages to
one of the queues that Scrutinizer workflow makes available.