<?php
namespace Feather2\Resource;

class Helper{
    public static function mkdir($dir, $mod = 0777){
        if(is_dir($dir)){
            return true;
        }else{
            $old = umask(0);

            if(@mkdir($dir, $mod, true) && is_dir($dir)){
                umask($old);
                return true;
            } else {
                umask($old);
            }
        }

        return false;
    }

    public static function readFile($file){
        if(is_file($file)){
            return file_get_contents($file);
        }

        return false;
    }

    public static function writeFile($file, $content){
        self::mkdir(dirname($file));
        file_put_contents($file, $content);
    }

    public static function get($data, $name, $default = null){
        return is_array($data) && isset($data[$name]) ? $data[$name] : $default;
    }

    public static function jsonEncode($data){
        return str_replace('\\', '', json_encode($data));
    }
}