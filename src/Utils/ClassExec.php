<?php
/**
 * Summary of namespace Vatcron\Utils
 */
namespace Vatcron\Utils;

class ClassExec {
    /**
     * 执行命令
     * 支持格式：
     * 1. Class::method
     * 2. Class@method
     * 3. Class::method()
     * 4. Class@method()
     * 5. Class::method(params)
     * 6. Class@method(params)
     */
    public static function execute($command, $additionalArgs = []) {
        // 分离类名和方法部分
        $parts = preg_split('/(::|@)/', $command, 2, PREG_SPLIT_DELIM_CAPTURE);
        
        if (count($parts) < 3) {
            throw new \Exception("无效的命令格式: {$command}");
        }
        
        list($className, $separator, $methodWithParams) = $parts;
        
        // 提取方法名和参数
        preg_match('/^(\w+)(?:\((.*)\))?$/', $methodWithParams, $matches);
        
        if (empty($matches)) {
            throw new \Exception("无法解析方法部分: {$methodWithParams}");
        }
        
        $methodName = $matches[1];
        $paramString = isset($matches[2]) ? $matches[2] : '';
        
        // 解析参数
        $args = self::parseParamString($paramString);
        
        // 合并额外参数
        if (!empty($additionalArgs)) {
            $args = array_merge($args, $additionalArgs);
        }
        
        // 检查类和方法是否存在
        self::validateCommand($className, $methodName);
        
        // 执行方法
        return call_user_func_array([$className, $methodName], $args);
    }
    
    /**
     * 解析参数字符串
     */
    private static function parseParamString($paramString) {
        if (empty($paramString)) {
            return [];
        }
        
        // 如果参数是JSON格式，优先使用JSON解析
        if (self::isJson($paramString)) {
            $args = json_decode($paramString, true);
            return $args === null ? [] : (array)$args;
        }
        
        // 否则使用传统解析
        return self::parseTraditionalParams($paramString);
    }
    
    /**
     * 判断是否为JSON格式
     */
    private static function isJson($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * 解析传统参数字符串
     */
    private static function parseTraditionalParams($paramString) {
        $args = [];
        $tokens = [];
        $current = '';
        $inString = false;
        $quoteChar = '';
        $escaped = false;
        
        $length = strlen($paramString);
        for ($i = 0; $i < $length; $i++) {
            $char = $paramString[$i];
            
            if (!$inString && $char === ',') {
                $tokens[] = trim($current);
                $current = '';
                continue;
            }
            
            if (!$escaped && ($char === "'" || $char === '"')) {
                if (!$inString) {
                    $inString = true;
                    $quoteChar = $char;
                } elseif ($quoteChar === $char) {
                    $inString = false;
                }
            }
            
            if ($char === '\\' && $inString) {
                $escaped = true;
            } else {
                $escaped = false;
            }
            
            $current .= $char;
        }
        
        if (!empty($current)) {
            $tokens[] = trim($current);
        }
        
        // 转换参数类型
        foreach ($tokens as $token) {
            $args[] = self::convertParamValue($token);
        }
        
        return $args;
    }
    
    /**
     * 转换参数值
     */
    private static function convertParamValue($value) {
        $value = trim($value);
        
        // 空值
        if ($value === '') {
            return '';
        }
        
        // null
        if (strtolower($value) === 'null') {
            return null;
        }
        
        // 布尔值
        if (strtolower($value) === 'true') {
            return true;
        }
        if (strtolower($value) === 'false') {
            return false;
        }
        
        // 数字
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        // 字符串（去除引号）
        if (($value[0] === "'" && substr($value, -1) === "'") ||
            ($value[0] === '"' && substr($value, -1) === '"')) {
            return stripslashes(substr($value, 1, -1));
        }
        
        // 数组（如 [1,2,3]）
        if ($value[0] === '[' && substr($value, -1) === ']') {
            return json_decode($value, true);
        }
        
        // 关联数组（如 {"key":"value"}）
        if ($value[0] === '{' && substr($value, -1) === '}') {
            return json_decode($value, true);
        }
        
        // 常量
        if (defined($value)) {
            return constant($value);
        }
        
        return $value;
    }
    
    /**
     * 验证命令
     */
    private static function validateCommand($className, $methodName) {
        if (!class_exists($className)) {
            throw new \Exception("类不存在: {$className}");
        }
        
        if (!method_exists($className, $methodName)) {
            throw new \Exception("方法不存在: {$className}::{$methodName}");
        }
        
        $reflection = new \ReflectionMethod($className, $methodName);
        if (!$reflection->isPublic()) {
            throw new \Exception("方法不可访问: {$className}::{$methodName}");
        }
        
        if (!$reflection->isStatic()) {
            throw new \Exception("方法不是静态方法: {$className}::{$methodName}");
        }
    }
}