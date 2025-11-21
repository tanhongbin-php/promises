<?php
declare(strict_types=1);

namespace config\plugin\thb\promises\api;

use GuzzleHttp\Promise\Utils;
use support\Container;

class Concurrent
{
    /** 并发执行任务
     *$promises = [
     * ['任务类', '方法', '参数'], //参数是数组
     * ['任务类', '方法', '参数'],
     * ['任务类', '方法', '参数'],
     * [$this, 'indexs', ['a', 1]]
     * ];
     */
    static function promises(array $promises = [], int $maxConcurrency = 10): array
    {
        $data = [];
        foreach ($promises as $key => $val) {
            if (count($val) < 2) {
                throw new \support\exception\BusinessException('promises参数格式错误', 500);
            }
            [$class, $method, $args] = array_pad($val, 3, []);
            $class = get_class($class);
            $data[$key] = [
                'url' => config('plugin.thb.promises.process.asyncHttp.listen') . config('plugin.thb.promises.app.api'),
                'data' => [
                    'class' => $class,
                    'method' => $method,
                    'args' => $args,
                    'sign' => strtoupper(md5($class . $method . env('AUTH_KEY', 'abc123'))),
                ]
            ];
        }
        // 1. 验证最大并发数（避免无效值）
        $maxConcurrency = max(1, $maxConcurrency); // 至少允许 1 个并发
        // 2. 将请求数据分块处理（根据最大并发数）
        $dataArr = array_chunk($data, $maxConcurrency, true);
        // 实例化请求类
        $client = Container::get('GuzzleHttp\Client');
        $responses = [];
        foreach ($dataArr as $data){
            //组装并发请求数组
            $promises = [];
            foreach ($data as $key => $val){
                $url = $val['url'];
                $fromData = $val['data'] ?? [];
                // 基础配置（超时、错误处理、SSL验证）
                $requestOptions['timeout'] = $fromData['timeout'] ?? 30;
                //$requestOptions['http_errors'] = $fromData['http_errors'] ?? false;
                $requestOptions['verify'] = $fromData['verify'] ?? false;
                $requestOptions['query'] = $fromData;
                $promises[$key] = $client->requestAsync('GET', $url, $requestOptions)->then(
                    function ($response) use ($key, &$responses) {
                        $responses[$key] = json_decode($response->getBody()->getContents());
                    }, function ($response) use ($key, &$responses) {
                        $responses[$key] = null;
                    }
                );
            }
            //执行并发请求
            Utils::settle($promises)->wait();
        }
        return $responses;
    }
}