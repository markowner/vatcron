<?php

return [
    'enable' => true,
    // 是否开启协程
    'enable_coroutine' => true,
    // 任务扫描间隔（秒）
    'scan_interval' => 5,
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
    // 是否将执行日志写入文件
    'log_write_file' => false,
];