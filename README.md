# Yii2 Daemon Supervisor

This extension provides controller to run multiple daemon commands as
Linux service.
 
## Installation

Preferred way to install extension is through [Composer](https://getcomposer.org).

```shell
$ composer require understeam/yii2-supervisor:~0.1 --prefer-dist
```

## Configuration

Add this controller to your console application configuration and
describe Yii commands which should run as daemons:

```php
...
'controllerMap' => [
    'class' => 'understeam\supervisor\SupervisorController',
    'phpBinary' => '/usr/bin/php',  // (optional) Path to php binary
    'yiiFile' => '@app/yii',        // (optional) Path to yii script file
    'commands' => [
        'my-process' => [       // Process group name 
            'command' => [
                'queue/listen', // Yii console action
                'default',      // Arguments
            ],
            'count' => 4,       // Process count
        ],
    ],
],
...
```

## Linux service

You can use this controller as Linux long-running service.
[There is](yii.example.service) an example of Unit configuration.
