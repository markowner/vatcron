# Vatcron - 高性能Webman定时任务插件

Vatcron是一个基于Webman框架的高性能定时任务插件，支持秒级任务调度、异步协程执行、实时日志推送等功能。

## 特性

- ✅ **秒级任务调度** - 支持秒级精度的任务调度
- ✅ **异步协程执行** - 基于Swoole协程的高性能异步执行
- ✅ **分布式锁机制** - 防止任务重复执行
- ✅ **实时日志推送** - WebSocket实时推送执行日志到前端
- ✅ **优雅关闭** - 支持进程优雅关闭和任务清理
- ✅ **任务重试** - 自动重试失败的任务
- ✅ **多种任务类型** - 支持类方法、Shell命令、HTTP请求等
- ✅ **Web管理界面** - 提供完整的API接口
- ✅ **高性能** - 基于Workerman和Swoole的高并发处理能力

## 安装

### 1. 通过Composer安装

```bash
composer require vat/vatcron
```

### 2. 执行安装命令

```bash
php webman vatcron:install
```

### 3. 配置插件

插件会自动创建配置文件 `config/plugin/vatcron/app.php`，你可以根据需要修改配置：

```php
return [
    'enable' => true,
    'cron' => [
        'scan_interval' => 1,        // 任务扫描间隔（秒）
        'max_concurrent' => 10,      // 最大并发任务数
        'timeout' => 300,            // 任务超时时间（秒）
        'log_retention_days' => 30,  // 日志保留天数
        'websocket' => [
            'enable' => true,        // 启用WebSocket日志推送
            'port' => 2346           // WebSocket服务端口
        ]
    ]
];
```

## 使用方法

### 1. 创建定时任务

#### 方式一：通过API创建

```bash
curl -X POST http://your-domain/cron/tasks/create \
  -H "Content-Type: application/json" \
  -d '{
    "name": "测试任务",
    "description": "这是一个测试任务",
    "cron_expression": "*/5 * * * *",
    "command": "Vatcron\\Example\\ExampleTasks::cacheWarmup",
    "enabled": true,
    "timeout": 300
  }'
```

#### 方式二：直接插入数据库

```php
use support\Db;

Db::table('vat_cron')->insert([
    'name' => '数据清理任务',
    'description' => '清理过期数据',
    'cron_expression' => '0 2 * * *',
    'command' => 'App\\Task\\CleanupTask::execute',
    'enabled' => 1,
    'timeout' => 600
]);
```

### 2. 支持的任务类型

#### 类方法任务

```php
// 命令格式：ClassName::methodName 或 ClassName@methodName
'command' => 'App\\Task\\MyTask::processData'
```

#### Shell命令任务

```php
// 直接执行Shell命令
'command' => '/usr/bin/php /path/to/script.php',
'params' => '{"arg1": "value1", "arg2": "value2"}'
```

#### HTTP请求任务

```php
// 执行HTTP请求
'command' => 'https://api.example.com/endpoint'
```

#### 闭包函数任务

```php
// 执行PHP闭包
'command' => 'function($params, $task, $logId) { 
    // 你的代码 
    return "执行完成"; 
}'
```

### 3. 任务管理API

| 接口 | 方法 | 说明 |
|------|------|------|
| `/cron/tasks` | GET | 获取任务列表 |
| `/cron/tasks/create` | POST | 创建任务 |
| `/cron/tasks/:id` | GET | 获取任务详情 |
| `/cron/tasks/:id/update` | POST | 更新任务 |
| `/cron/tasks/:id/delete` | POST | 删除任务 |
| `/cron/tasks/:id/execute` | POST | 立即执行任务 |
| `/cron/tasks/:id/toggle` | POST | 启用/禁用任务 |
| `/cron/tasks/:id/logs` | GET | 获取任务日志 |
| `/cron/status` | GET | 获取系统状态 |

### 4. 实时日志监控

插件提供WebSocket服务用于实时监控任务执行情况：

```javascript
// 前端JavaScript代码示例
const ws = new WebSocket('ws://your-domain:2346');

ws.onopen = function() {
    // 订阅日志频道
    ws.send(JSON.stringify({
        type: 'subscribe',
        channel: 'vatcron:logs'
    }));
    
    ws.send(JSON.stringify({
        type: 'subscribe', 
        channel: 'vatcron:execution_logs'
    }));
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    
    if (data.type === 'log') {
        console.log('任务日志:', data.data);
        // 更新UI显示
    }
};
```

## 高级功能

### 1. 自定义任务类

创建自定义任务类，实现复杂的业务逻辑：

```php
<?php

namespace App\Task;

use support\Db;
use support\Redis;

class MyCustomTask
{
    protected $taskInfo;
    protected $logId;

    // 自动注入任务信息（可选）
    public function setTaskInfo($taskInfo, $logId)
    {
        $this->taskInfo = $taskInfo;
        $this->logId = $logId;
    }

    public function processData($param1, $param2)
    {
        // 你的业务逻辑
        
        // 实时推送进度
        $this->pushProgress("处理中...");
        
        // 执行耗时操作
        $result = $this->heavyProcessing();
        
        return "处理完成: " . $result;
    }
    
    protected function pushProgress($message)
    {
        if ($this->logId) {
            Redis::publish('vatcron:execution_logs', json_encode([
                'log_id' => $this->logId,
                'level' => 'info',
                'message' => $message,
                'timestamp' => time(),
                'type' => 'progress'
            ]));
        }
    }
}
```

### 2. 任务重试机制

任务执行失败时会自动重试：

```php
// 在任务配置中设置重试参数
'command' => 'App\\Task\\MyTask::process',
'max_retries' => 3,      // 最大重试次数
'retry_delay' => 60      // 重试延迟（秒）
```

### 3. 分布式部署

在多服务器环境下，插件会自动处理任务锁，避免重复执行：

- 基于Redis的分布式锁
- 数据库锁记录用于监控
- 自动清理过期锁

## 性能优化建议

### 1. 合理设置扫描间隔

```php
'scan_interval' => 1  // 生产环境建议1-5秒
```

### 2. 控制并发任务数

```php
'max_concurrent' => 10  // 根据服务器配置调整
```

### 3. 设置合理的超时时间

```php
'timeout' => 300  // 根据任务类型设置
```

### 4. 定期清理日志

```php
'log_retention_days' => 30  // 保留30天日志
```

## 故障排除

### 1. 任务不执行

- 检查任务是否启用 (`enabled = 1`)
- 检查Cron表达式是否正确
- 查看系统日志是否有错误信息
- 检查数据库连接是否正常

### 2. 任务重复执行

- 检查分布式锁机制是否正常工作
- 确认没有多个调度器进程在运行
- 检查服务器时间是否同步

### 3. 内存泄漏

- 检查任务代码是否有内存泄漏
- 设置合理的超时时间
- 定期重启Worker进程

### 4. 性能问题

- 减少任务扫描频率
- 优化任务执行代码
- 增加服务器资源

## 开发指南

### 1. 扩展任务类型

你可以通过继承 `Vatcron\\Task\\TaskExecutor` 类来支持新的任务类型：

```php
class CustomExecutor extends TaskExecutor
{
    protected function executeCustomType($command, $params, $task, $logId)
    {
        // 实现自定义任务类型的执行逻辑
    }
}
```

### 2. 添加新的监控指标

修改 `TaskController::status` 方法添加自定义监控指标。

### 3. 自定义WebSocket协议

继承 `LogWebSocketProcess` 类实现自定义的实时通信协议。

## 许可证

MIT License

## 贡献

欢迎提交Issue和Pull Request来改进这个项目。

## 支持

如果你在使用过程中遇到问题，可以：

1. 查看项目文档和示例
2. 提交GitHub Issue
3. 联系开发团队

## 版本历史

- v1.0.0 (2024-01-01): 初始版本发布
  - 基础定时任务功能
  - 异步协程执行
  - 实时日志推送
  - Web管理API
  - 分布式锁支持