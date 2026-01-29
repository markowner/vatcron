<?php

namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Workerman\Worker;

#[AsCommand(name: 'vatcron', description: 'Vatcron定时任务管理命令')]
class VatcronCommand extends Command
{
    /**
     * @param OutputInterface $output
     * @param string $action
     * @param bool $daemon
     * @return int
     */
    public function __invoke(
        OutputInterface $output,
        #[Argument(description: '操作类型: start, stop, restart, status, reload, connections')] string $action,
        #[Option(description: '后台运行', shortcut: 'd')] bool $daemon = false
    ): int {
        // 构造 Workerman 需要的参数
        global $argv;
        $argv[0] = 'vatcron';
        $argv[1] = $action;
        if ($daemon) {
            $argv[2] = '-d';
        } else {
            // 清理可能存在的后续参数，确保 Workerman 解析正确
            if (isset($argv[2])) {
                unset($argv[2]);
            }
        }

        $config = config('plugin.vatcron.process_cron');

        // 设置 Workerman 全局配置
        $runtimeDir = runtime_path() . '/logs/vatcron';
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0755, true);
        }
        
        Worker::$pidFile = $runtimeDir . '/workerman.pid';
        Worker::$logFile = $runtimeDir . '/workerman.log';
        
        // 启动进程
        foreach ($config as $name => $item) {
            $worker = new Worker($item['listen'] ?? null);
            $worker->name = $name;
            $worker->count = $item['count'] ?? 1;
            
            if (isset($item['handler'])) {
                $handlerClass = $item['handler'];
                $constructor = $item['constructor'] ?? [];
                
                $worker->onWorkerStart = function ($worker) use ($handlerClass, $constructor) {
                    $instance = new $handlerClass(...array_values($constructor));
                    if (method_exists($instance, 'onWorkerStart')) {
                        $instance->onWorkerStart($worker);
                    }
                    if (method_exists($instance, 'onWorkerStop')) {
                        $worker->onWorkerStop = [$instance, 'onWorkerStop'];
                    }
                    if (method_exists($instance, 'onConnect')) {
                        $worker->onConnect = [$instance, 'onConnect'];
                    }
                    if (method_exists($instance, 'onMessage')) {
                        $worker->onMessage = [$instance, 'onMessage'];
                    }
                    if (method_exists($instance, 'onClose')) {
                        $worker->onClose = [$instance, 'onClose'];
                    }
                };
            }
            
            if (isset($item['user'])) {
                $worker->user = $item['user'];
            }
            if (isset($item['group'])) {
                $worker->group = $item['group'];
            }
            if (isset($item['reusePort'])) {
                $worker->reusePort = $item['reusePort'];
            }
            if (isset($item['eventLoop'])) {
                Worker::$eventLoopClass = $item['eventLoop'];
            }
        }

        Worker::runAll();

        return self::SUCCESS;
    }
}
