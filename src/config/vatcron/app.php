<?php

return [
    // 是否开启定时任务
    'enable' => true,
    // 是否开启协程
    'enable_coroutine' => true,
    // 监听地址
    'listen_cron' => '0.0.0.0:12346',
    // 执行地址
    'listen_cron_exec' => '0.0.0.0:12347',
    // 日志监听地址,用于实时日志推送
    'listen_cron_log' => '0.0.0.0:12348',
    // 任务扫描间隔（秒）
    'scan_interval' => 1,
    // 最大并发任务数
    'max_concurrent' => 10,
    // 最大内存占用（MB）
    'max_memory_per_process' => '128M',
    // 最大执行时间（秒）
    'max_execution_time' => 300,
    // 定时任务表名
    'table_cron' => 'vat_cron',
    // 定时任务日志表名
    'table_log' => 'vat_cron_log',
    // 定时任务队列名称
    'cron_queue' => 'vatcron:cron_queue',
    // 定时任务日志队列名称
    'lock_prefix' => 'vatcron:lock:',
    // 定时任务日志订阅频道
    'log_subscribe' => 'vatcron:logs',
];