<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Request;
use Webman\Route;

/**
 * 内部并发处理任务的api接口地址
 */
Route::post(config('plugin.thb.promises.app.api'), function(Request $request){
    $class = $request->input('class', '');
    if(strlen($class) == 0 || !class_exists($class)){
        return response('forbidden', 403);
    }
    $method = $request->input('method', '');
    if(strlen($method) == 0 || !method_exists($class, $method)){
        return response('forbidden', 403);
    }
    $args = $request->input('args', []);
    if(strtoupper(md5($class . $method . config('plugin.thb.promises.app.secret'))) !== $request->input('sign', '')){
        return response('forbidden', 403);
    }
    $class = \support\Container::get($class);
    $res = call_user_func_array([$class, $method], [...$args]);
    return response(json_encode($res, JSON_UNESCAPED_UNICODE));
});