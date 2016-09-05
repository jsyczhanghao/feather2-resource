<?php
namespace Feather2\Resource;

class Maps{
    private $maps = array(); 
    private $dirs = array();
    private $loadedFiles = array();
    private $lastModifyTime = 0;
    private static $IMPORTANT_TYPES = array('headJs', 'bottomJs', 'css', 'asyncs', 'deps', 'refs');   
    private static $COMMON_JS = array('static/pagelet.js', 'static/feather.js');

    public function __construct($dirs = array()){
        $this->dirs = (array)$dirs;
    }

    public function get($id){
        $info = self::pathinfo($id);

        $this->initNameSpaceMap($info['namespace']);

        return Helper::get($this->maps, $info['id']);
    }

    public function getIncludeRefs($id, $isRef = false){
        if($info = $this->get($id)){
            $refsInfo = array();
            $refs = Helper::get($info, 'refs', array());

            foreach($refs as $ref){
                if($refInfo = $this->getIncludeRefs($ref, true)){
                    $refsInfo = array_merge_recursive($refsInfo, $refInfo);
                }
            }

            if(!$isRef){
                foreach($refsInfo as $k => $v){
                    if(array_search($k, self::$IMPORTANT_TYPES) === false){
                        unset($refsInfo[$k]);
                    }
                }
            }

            return array_merge_recursive($refsInfo, $info);
        }
    }

    private function initNameSpaceMap($namespace){
        if($namespace != 'common'){
            $this->initNameSpaceMap('common');
        }

        if(isset($this->loadedFiles[$namespace])){
            return true;
        }
        
        //合并map表
        foreach($this->dirs as $key => $dir){
            $file = "{$dir}/{$namespace}.json";

            if(is_file($file)){
                $map = json_decode(file_get_contents($file), true);
                $this->maps = array_merge($this->maps, $map);
                $this->loadedFiles[$namespace] = $file;
                break;
            }
        }
    }

    public function getLastModifyTime(){ 
        if(!$this->lastModifyTime){
            foreach($this->dirs as $dir){
                if(is_dir($dir)){
                    foreach((array)glob("{$dir}/**.json") as $file){
                        clearstatcache();
                        $modifyTime = filemtime($file);

                        if($modifyTime > $this->lastModifyTime){
                            $this->lastModifyTime = $modifyTime;
                        }
                    }
                }
            }  
        }
       
        return $this->lastModifyTime;
    }

    public static function pathinfo($id){
        if(array_search($id, self::$COMMON_JS) !== false){
            return array(
                'namespace' => 'common',
                'id' => $id
            );
        }

        $s = explode(':', $id);

        if(count($s) == 1){
            $s = explode('/', $id);
        }

        $ns = $s[0];
        $id = sprintf('%s:%s', $ns, implode('/', array_slice($s, 1)));  

        return array(
            'namespace' => $ns,
            'id' => $id
        );
    }
}