<?php
declare(strict_types=1);

namespace config\plugin\thb\promises\api;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\FulfilledPromise; // Guzzle 7 原生类
use GuzzleHttp\Promise\Utils;
use support\exception\BusinessException;

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
        static $client;
        // 验证最大并发数（至少1个，最多不超过请求总数）
        $maxConcurrency = config('plugin.thb.promises.app.count', 8);
        $data = [];
        $i = 0;
        foreach ($promises as $key => $val) {
            if (count($val) < 2) {
                throw new BusinessException('promises参数格式错误', 500);
            }
            [$class, $method, $args] = array_pad($val, 3, []);
            $className = is_object($class) ? get_class($class) : $class; // 兼容类名/实例
            $asyncHttp = 'asyncHttp' . $i%$maxConcurrency;
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
        // 验证最大并发数（至少1个，最多不超过请求总数）
        $totalRequests = count($data);
        if(!$client){
            $client = new Client([
                'handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\CurlMultiHandler([
                    'max_concurrency' => $maxConcurrency, // 连接池最大并发数，与业务并发数一致
                ])),
            ]);
        }

        $responses = [];

        // 1. 用 SplQueue 存储待执行的请求（键+配置），FIFO 顺序
        $queue = new \SplQueue();
        foreach ($data as $key => $config) {
            $queue->enqueue([$key, $config]);
        }

        // 2. 递归执行函数：从队列取请求，执行后补下一个
        $execute = function () use (
            &$execute,  // 递归引用自身
            &$queue,    // 待执行队列
            $client,    // Guzzle客户端
            &$responses // 存储结果
        ) {
            // 队列空了，返回已完成的Promise，终止递归
            if ($queue->isEmpty()) {
                return new FulfilledPromise(null);
            }

            // 取出队列头部的请求
            [$key, $config] = $queue->dequeue();
            $fromData = $config['data'] ?? [];

            // 独立请求配置（避免变量污染，每次重新创建）
            $requestOptions = [
                'timeout' => $fromData['timeout'] ?? 15,
                'verify' => $fromData['verify'] ?? false,
                'json' => $fromData,
            ];
            // 发起异步GET请求
            return $client->requestAsync('POST', $config['url'], $requestOptions)
                ->then(
                // 成功回调：解析响应（兼容JSON和非JSON）
                    function ($response) use ($key, &$responses) {
                        $body = $response->getBody()->getContents();
                        $parsed = json_decode($body, true);
                        $responses[$key] = $parsed !== null ? $parsed : $body;
                    },
                    // 失败回调：记录错误信息（方便排查）
                    function ($exception) use ($key, &$responses) {
                        $responses[$key] = [
                            'error' => true,
                            'message' => $exception->getMessage(),
                            'code' => $exception->getCode(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ];
                    }
                )
                // 关键：当前请求完成后，立即执行下一个请求（维持并发数）
                ->then($execute);
        };

        // 3. 初始化：启动 maxConcurrency 个并发请求（核心并发控制）
        $runningPromises = [];
        $maxConcurrency = min($totalRequests, $maxConcurrency);
        for ($i = 0; $i < $maxConcurrency; $i++) {
            $runningPromises[] = $execute();
        }

        // 4. 等待所有请求（包括递归补充的）全部完成
        Utils::all($runningPromises)->wait();

        // 确保返回结果的键与输入顺序一致（可选，按原key排序）
        ksort($responses);
        return $responses;
    }
}