<?php
namespace Feather2\Resource;

class Cache{
    public $dir;

    const SUFFIX = '.cache';

    public function __construct($dir){
        $this->dir = rtrim($dir, '/') . '/';
    }

    public function get($id){
        $path = $this->getCacheFilePath($id);

        if($content = Helper::readFile($path)){
            return unserialize($content);
        }

        return false;
    }

    public function set($id, $data = array()){
        $path = $this->getCacheFilePath($id);
        return Helper::writeFile($path, serialize($data));
    }

    private function getCacheFilePath($file){
        return $this->dir . md5($file) . self::SUFFIX;
    }
}