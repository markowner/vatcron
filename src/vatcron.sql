-- 创建vat_cron表（任务表）
CREATE TABLE IF NOT EXISTS `vat_crontab` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '任务ID',
  `name` varchar(100) NOT NULL COMMENT '任务名称',
  `description` text COMMENT '任务描述',
  `cron_expression` varchar(50) NOT NULL COMMENT 'Cron表达式',
  `command` text NOT NULL COMMENT '执行命令',
  `task_type` tinyint(1) DEFAULT 1 COMMENT '任务类型：1 command, 2 class, 3 url, 4 shell',
  `execution_mode` tinyint(1) DEFAULT 0 COMMENT '执行模式：0自动（优先协程）, 1协程, 2进程',
  `run_once` tinyint(1) DEFAULT 0 COMMENT '是否只执行一次',
  `max_retries` int(11) DEFAULT 3 COMMENT '最大重试次数',
  `retry_delay` int(11) DEFAULT 60 COMMENT '重试延迟（秒）',
  `timeout` int(11) DEFAULT 300 COMMENT '任务超时时间（秒）',
  `lock_time` int(11) DEFAULT 300 COMMENT '任务锁时间（秒）',
  `last_run_time` datetime DEFAULT NULL COMMENT '上次执行时间',
  `next_run_time` datetime DEFAULT NULL COMMENT '下次执行时间',
  `status` tinyint(1) DEFAULT 0 COMMENT '状态',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务表';

-- 创建vat_cron_log表（任务执行日志表）
CREATE TABLE IF NOT EXISTS `vat_crontab_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `cron_id` int(11) NOT NULL COMMENT '任务ID',
  `task_name` varchar(100) NOT NULL COMMENT '任务名称',
  `status` enum('running','success','failed') NOT NULL DEFAULT 'running' COMMENT '执行状态',
  `output` text COMMENT '执行输出',
  `error` text COMMENT '错误信息',
  `start_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '开始执行时间',
  `end_time` datetime DEFAULT NULL COMMENT '结束执行时间',
  `duration` int(11) DEFAULT NULL COMMENT '执行时长（秒）',
  `pid` int(11) DEFAULT NULL COMMENT '进程ID',
  `retry_count` int(11) DEFAULT 0 COMMENT '重试次数',
  PRIMARY KEY (`id`),
  KEY `idx_cron_id` (`cron_id`),
  KEY `idx_start_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务执行日志表';