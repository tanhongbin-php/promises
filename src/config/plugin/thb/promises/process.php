<?php

use app\process\Http;
use support\Log;
use support\Request;

$arr = [];
for ($i = 0; $i < config('plugin.thb.promises.app.count', 8); $i++)
{
    $arr[config('plugin.thb.promises.app.name', 'asyncHttp') . $i] = [
        'handler' => Http::class,
        'listen' => 'http://127.0.0.1:' . (config('plugin.thb.promises.app.port', 8600) + $i),
        'count' => 1,
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
    ];
}
return $arr;