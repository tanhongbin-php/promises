<?php
declare(strict_types=1);

namespace config\plugin\thb\promises\api;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use support\exception\BusinessException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\HandlerStack;

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
    static function promises(array $promises = [], int $maxConcurrency = 8): array
    {
        static $i = 0;
        if(strlen(ProcessName::$name)){
            throw new BusinessException('不能在异步进程内再次使用异步方法', 500);
        }
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $proClass = $caller[1]['class'] ?? '';
        $proFunction = $caller[1]['function'] ?? '';
        $asyncHttpNum = config('plugin.thb.promises.app.count', 1);
        $data = [];
        foreach ($promises as $key => $val) {
            if (!is_array($val) || count($val) < 2) {
                throw new BusinessException('promises参数格式错误', 500);
            }
            [$class, $method, $args] = array_pad($val, 3, []);
            $className = is_object($class) ? get_class($class) : $class; // 兼容类名/实例
            if($proClass === $className && $proFunction === $method){
                throw new BusinessException('promises不支持自身调用自身', 500);
            }
            $asyncHttp = 'asyncHttp' . $i%$asyncHttpNum;
            $data[$key] = [
                'url' => config('plugin.thb.promises.process.' . $asyncHttp . '.listen') . config('plugin.thb.promises.app.api'),
                'data' => [
                    'class' => $className,
                    'method' => $method,
                    'args' => $args,
                    'sign' => strtoupper(md5($className . $method . config('plugin.thb.promises.app.secret'))),
                ]
            ];
            $i++;
        }
        if($i > $asyncHttpNum * 100){
            $i = 1;
        }
        // 验证最大并发数（至少1个，最多不超过请求总数）
        $totalRequests = count($data);
        // 每次调用创建独立的 Client/Handler，避免长生命周期句柄积累
        $multiHandler = new CurlMultiHandler([
            // 限制内部可复用句柄上限，避免池无限增长
            'handle_factory' => new CurlFactory($asyncHttpNum),
            'select_timeout' => 0.05,
        ]);
        $handlerStack = HandlerStack::create($multiHandler);
        $client = new Client([
            'handler' => $handlerStack,
        ]);

        $responses = [];
        $concurrency = min($totalRequests, $maxConcurrency);

        // 使用 Guzzle Pool 管理并发请求
        $requests = function () use ($data, $client) {
            foreach ($data as $key => $config) {
                yield $key => function () use ($client, $config) {
                    $fromData = $config['data'] ?? [];
                    $options = [
                        'timeout' => $fromData['timeout'] ?? 15,
                        'verify' => $fromData['verify'] ?? false,
                        'json' => $fromData,
                        // 短连接策略，降低长连接导致的粘连与内存占用
                        'headers' => ['Connection' => 'close', 'Expect' => ''],
                        'curl' => [
                            \CURLOPT_FORBID_REUSE => true,
                            \CURLOPT_FRESH_CONNECT => true,
                            \CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        ],
                    ];
                    return $client->requestAsync('POST', $config['url'], $options);
                };
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $key) use (&$responses) {
                $body = $response->getBody()->getContents();
                $parsed = json_decode($body, true);
                $responses[$key] = $parsed !== null ? $parsed : $body;
            },
            'rejected' => function ($reason, $key) use (&$responses) {
                $exception = $reason instanceof \Throwable ? $reason : new \RuntimeException((string)$reason);
                $responses[$key] = [
                    'error' => true,
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ];
            },
        ]);

        // 等待所有请求完成
        $pool->promise()->wait();

        // 显式释放大对象，减少长期驻留内存
        unset($data, $requests, $pool);
        $client = null;
        $handlerStack = null;
        $multiHandler = null;
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        return $responses;
    }
}