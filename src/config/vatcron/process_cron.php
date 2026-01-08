<?php

use Vatcron\Process\CronScheduler;
use Vatcron\Process\CronExec;
use Vatcron\Process\LogSocket;
use support\Log;

return [
    // 定时任务调度器进程
    'vatcron_scheduler' => [
        'handler' => CronScheduler::class,
        'listen'  => 'text://0.0.0.0:12346',
        'count' => 1, // 单进程运行，避免重复调度
        'context' => [],
        'constructor' => [
            'logger' => Log::channel('default'),
        ]
    ],
    'vatcron_exec' => [
        'handler' => CronExec::class,
        'listen'  => 'text://0.0.0.0:12347',
        'count' => 1,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        // 开启协程需要设置为 Workerman\Events\Swoole::class 或者 Workerman\Events\Swow::class 或者 Workerman\Events\Fiber::class
        'eventLoop' => Workerman\Events\Swoole::class,
        'context' => [],
        'constructor' => [
            'logger' => Log::channel('default'),
        ]
    ],
    // 实时日志WebSocket服务进程
    'vatcron_websocket' => [
        'handler' => LogSocket::class,
        'listen'  => 'websocket://0.0.0.0:12348',
        'count' => 1,
        'eventLoop' => Workerman\Events\Swoole::class,
        'constructor' => [
            'logger' => Log::channel('default'),
        ]
    ],
];
