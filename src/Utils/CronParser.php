<?php

namespace Vatcron\Utils;

/**
 * 秒级 Cron 表达式解析器
 */
class CronParser
{
    /**
     * Cron 字段映射
     */
    const FIELDS = [
        'second' => [0, 59],      // 秒
        'minute' => [0, 59],      // 分
        'hour'   => [0, 23],      // 时
        'day'    => [1, 31],      // 日
        'month'  => [1, 12],      // 月
        'week'   => [0, 6],       // 周 (0=周日, 6=周六)
        'year'   => [1970, 2099]  // 年 (可选)
    ];

    /**
     * 月份名称映射
     */
    const MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 
        'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 
        'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12
    ];

    /**
     * 星期名称映射
     */
    const WEEK_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 
        'THU' => 4, 'FRI' => 5, 'SAT' => 6
    ];

    /**
     * 解析 Cron 表达式
     *
     * @param string $expression Cron 表达式
     * @return array 解析结果
     * @throws \InvalidArgumentException 表达式格式错误时抛出
     */
    public static function parse(string $expression): array
    {
        // 去除多余空格
        $expression = preg_replace('/\s+/', ' ', trim($expression));
        $fields = explode(' ', $expression);
        
        // 检查字段数量
        $fieldCount = count($fields);
        if ($fieldCount !== 6 && $fieldCount !== 7) {
            throw new \InvalidArgumentException(
                "Invalid cron expression: {$expression}. Expected 6 or 7 fields, got {$fieldCount}."
            );
        }

        $result = [];
        $fieldKeys = array_keys(self::FIELDS);
        
        // 解析每个字段
        for ($i = 0; $i < $fieldCount; $i++) {
            $field = $fields[$i];
            $fieldName = $fieldKeys[$i];
            $range = self::FIELDS[$fieldName];
            
            // 解析单个字段
            $result[$fieldName] = self::parseField($field, $range, $fieldName);
        }

        return $result;
    }

    /**
     * 解析单个 Cron 字段
     *
     * @param string $field 字段值
     * @param array $range 字段范围
     * @param string $fieldName 字段名称
     * @return array 解析后的允许值
     */
    private static function parseField(string $field, array $range, string $fieldName): array
    {
        $min = $range[0];
        $max = $range[1];
        
        // 处理通配符
        if ($field === '*' || $field === '?') {
            return range($min, $max);
        }

        // 处理月份和星期名称
        $field = self::replaceNames($field);

        $values = [];
        
        // 处理逗号分隔的多个表达式
        foreach (explode(',', $field) as $part) {
            // 处理范围表达式 (e.g. 1-5)
            if (strpos($part, '-') !== false) {
                $rangeValues = self::parseRange($part, $min, $max);
                $values = array_merge($values, $rangeValues);
            } 
            // 处理步长表达式 (e.g. */5, 1-10/2)
            elseif (strpos($part, '/') !== false) {
                $stepValues = self::parseStep($part, $min, $max);
                $values = array_merge($values, $stepValues);
            } 
            // 处理单个值 (e.g. 5)
            else {
                $value = (int)$part;
                if ($value >= $min && $value <= $max) {
                    $values[] = $value;
                }
            }
        }

        // 去重并排序
        $values = array_unique($values);
        sort($values);
        
        return $values;
    }

    /**
     * 替换月份和星期名称为数字
     */
    private static function replaceNames(string $field): string
    {
        // 替换月份名称
        $field = strtr(strtoupper($field), self::MONTH_NAMES);
        // 替换星期名称
        $field = strtr(strtoupper($field), self::WEEK_NAMES);
        
        return $field;
    }

    /**
     * 解析范围表达式 (e.g. 1-5)
     */
    private static function parseRange(string $rangeStr, int $min, int $max): array
    {
        list($start, $end) = explode('-', $rangeStr);
        $start = (int)$start;
        $end = (int)$end;

        // 处理步长 (e.g. 1-10/2)
        if (strpos($end, '/') !== false) {
            list($end, $step) = explode('/', $end);
            $end = (int)$end;
            $step = (int)$step;
        } else {
            $step = 1;
        }

        // 验证范围
        $start = max($start, $min);
        $end = min($end, $max);

        $values = [];
        for ($i = $start; $i <= $end; $i += $step) {
            $values[] = $i;
        }

        return $values;
    }

    /**
     * 解析步长表达式 (e.g. * / 5, 1-10/2)
     */
    private static function parseStep(string $stepStr, int $min, int $max): array
    {
        list($base, $step) = explode('/', $stepStr);
        $step = (int)$step;
        
        if ($base === '*') {
            // 完整范围的步长 (e.g. */5)
            $start = $min;
            $end = $max;
        } elseif (strpos($base, '-') !== false) {
            // 范围内的步长 (e.g. 1-10/2)
            list($start, $end) = explode('-', $base);
            $start = (int)$start;
            $end = (int)$end;
        } else {
            // 单个值的步长 (e.g. 5/2)
            $start = (int)$base;
            $end = $max;
        }

        // 验证范围
        $start = max($start, $min);
        $end = min($end, $max);

        $values = [];
        for ($i = $start; $i <= $end; $i += $step) {
            $values[] = $i;
        }

        return $values;
    }

    /**
     * 计算下一次执行时间
     *
     * @param string $expression Cron 表达式
     * @param int|null $baseTime 基准时间 (默认当前时间)
     * @return int 下一次执行的时间戳
     */
    public static function getNextRunTime(string $expression, ?int $baseTime = null): int
    {
        $baseTime = $baseTime ?? time();
        $parsed = self::parse($expression);
        
        // 从下一秒开始检查
        $nextTime = $baseTime + 1;
        
        // 最多尝试一年
        $maxAttempts = 366 * 24 * 60 * 60;
        $attempts = 0;
        
        while ($attempts < $maxAttempts) {
            $timeParts = self::getTimeParts($nextTime);
            
            // 检查每个字段是否匹配
            if (self::isMatch($timeParts, $parsed)) {
                return $nextTime;
            }
            
            $nextTime++;
            $attempts++;
        }
        
        throw new \RuntimeException("Could not find next run time for expression: {$expression}");
    }

    /**
     * 获取时间的各个部分
     *
     * @param int $timestamp 时间戳
     * @return array 时间部分
     */
    private static function getTimeParts(int $timestamp): array
    {
        $time = getdate($timestamp);
        
        return [
            'second' => $time['seconds'],
            'minute' => $time['minutes'],
            'hour'   => $time['hours'],
            'day'    => $time['mday'],
            'month'  => $time['mon'],
            'week'   => $time['wday'],
            'year'   => $time['year']
        ];
    }

    /**
     * 检查时间是否匹配解析后的 Cron 表达式
     *
     * @param array $timeParts 时间部分
     * @param array $parsed 解析后的 Cron 表达式
     * @return bool 是否匹配
     */
    private static function isMatch(array $timeParts, array $parsed): bool
    {
        // 检查每个字段
        foreach ($parsed as $fieldName => $allowedValues) {
            if (!in_array($timeParts[$fieldName], $allowedValues, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 验证 Cron 表达式格式是否正确
     *
     * @param string $expression Cron 表达式
     * @return bool 是否有效
     */
    public static function validate(string $expression): bool
    {
        try {
            self::parse($expression);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取最近几次的执行时间
     *
     * @param string $expression Cron 表达式
     * @param int $count 次数
     * @param int|null $baseTime 基准时间
     * @return array 时间戳数组
     */
    public static function getNextRunTimes(string $expression, int $count = 5, ?int $baseTime = null): array
    {
        $baseTime = $baseTime ?? time();
        $times = [];
        
        for ($i = 0; $i < $count; $i++) {
            $nextTime = self::getNextRunTime($expression, $baseTime);
            $times[] = $nextTime;
            $baseTime = $nextTime;
        }
        
        return $times;
    }
}