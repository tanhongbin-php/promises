<?php

namespace Thb\Promises\config\plugin\thb\promises\api;

use Webman\Bootstrap;

class ProcessName implements Bootstrap
{
    public static string $name = '';
    public static function start($worker)
    {
        if(strpos($worker->name, 'plugin.thb.promises.' . config('plugin.thb.promises.app.name', 'asyncHttp')) !== false){
            self::$name = $worker->name ?? '';
        }
    }
}
