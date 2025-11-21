<?php

use app\process\Http;
use support\Log;
use support\Request;

return [
    'asyncHttp' => [
        'handler' => Http::class,
        'listen' => 'http://127.0.0.1:8686',
        'count' => cpu_count() * 2,
        'user' => '',
        'group' => '',
        'reusePort' => true,
        'eventLoop' => '', //Workerman\Events\Fiber::class
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
];