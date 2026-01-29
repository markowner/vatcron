<?php

namespace Vatcron;

class Install
{
    const WEBMAN_PLUGIN = true;

    protected static $pathRelation = [
        'config/vatcron' => 'config/plugin/vat/vatcron',
        'command' => 'app/command',
    ];

    public static function install()
    {
        static::installByRelation();
    }

    public static function uninstall()
    {
        self::uninstallByRelation();
    }

    public static function installByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent_dir = base_path().'/'.substr($dest, 0, $pos);
                if (!is_dir($parent_dir)) {
                    mkdir($parent_dir, 0777, true);
                }
            }
            $sourcePath = __DIR__ . "/$source";
            $destPath = base_path()."/$dest";

            if (is_dir($sourcePath)) {
                copy_dir($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
            echo "Create $dest\n";
        }
    }

    public static function uninstallByRelation()
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path()."/$dest";
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            echo "Remove $dest\n";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            remove_dir($path);
        }
    }
}
