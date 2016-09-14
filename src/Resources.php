<?php
namespace Feather2\Resource;

class Resources{
    private $maps;
    private $options;
    private $combo;
    private $urlCache = array();
    private $pageletCss = array();
    private $templateDir;
    private $cache;
    private static $RESOURCE_TYPES = array('headJs', 'bottomJs', 'css');  
    const COMBO_MAX_URL_LENGTH = 2000;
    const LOADER = 'static/feather.js';

    public function __construct($templateDir, $options = array()){
        $this->templateDir = (array)$templateDir;
        $this->options = $options;

        $mapDirs = array();

        foreach($this->templateDir as $dir){
            $mapDirs[] = $dir . '/_map_';
        }

        if($cacheDir = Helper::get($this->options, 'cacheDir')){
            $this->cache = new Cache($cacheDir);
        }

        $this->maps = new Maps($mapDirs);
    }

    private function getUrls($resources, $returnHash = false, $includeNotFound = false, &$founds = array(), &$pkgFounds = array()){
        $urls = array();

        foreach($resources as $resource){
            $info = $this->maps->get($resource);
            $url = Helper::get($founds, $resource);

            if($info){
                $pkgInfo = null;

                //if pack
                if($pkgName = Helper::get($info, 'pkg')){
                    $url = Helper::get($pkgFounds, $pkgName);
    
                    //if pkg exists but not in pkgFounds
                    if(!$url){
                        $pkgInfo = $this->maps->get($pkgName);
                        //cache pack info
                        $url = $pkgFounds[$pkgName] = $pkgInfo['url'];
                        $this->urlCache[$url] = $pkgInfo;
                    }
                }else{
                    $url = $info['url'];
                    $this->urlCache[$url] = $info;
                }

                //store id
                $founds[$resource] = $url;

                //anaylse self deps
                if($deps = Helper::get($info, 'deps')){
                    $urls = array_merge($this->getUrls($deps, false, $includeNotFound, $founds, $pkgFounds), $urls);
                }

                //if asyncs, analyse asyncs
                if($asyncs = Helper::get($info, 'asyncs')){
                    $urls = array_merge($this->getUrls($asyncs, false, $includeNotFound, $founds, $pkgFounds), $urls);
                }

                //Requrie all files to prevent call error when all files in pkg don't use jswraper 
                if(isset($pkgInfo) && isset($pkg['useJsWraper'])){
                    $noWraperHas = array();

                    foreach($pkgInfo['has'] as $has){
                        //Only analyse which is not analysed
                        if($has = Helper::get($founds, $has)){
                            $noWraperHas = $has;
                        }
                    }

                    if(!empty($noWraperHas)){
                        $urls = array_merge($this->getUrl($noWraperHas, false, $includeNotFound, $founds, $pkgFounds), $urls);
                    }
                }
            }else{
                $url = $resource;

                if($includeNotFound){
                    $founds[$resource] = $resource;
                }
            }

            $urls[] = $url;
        }

        return $returnHash ? $founds : array_unique($urls);
    }

    private function getThreeUrls($mapInfo){
        $inJsCss = array();
        $allUrls = array();

        foreach(self::$RESOURCE_TYPES as $type){
            $resources = Helper::get($mapInfo, $type, array());
            $urls = $this->getUrls($resources, false, true);

            if($type != 'css'){
                foreach($urls as $key => $url){
                    $info = Helper::get($this->urlCache, $url);

                    if($info && $info['type'] == 'css'){
                        $inJsCss[] = $url;
                        unset($urls[$key]);
                    }
                }
            }

            $allUrls[$type] = $urls;
        }

        $allUrls['css'] = array_merge($allUrls['css'], $inJsCss);

        $comboOptions = Helper::get($this->options, 'combo');
        $comboOnlyUnPackFile = Helper::get($comboOptions, 'onlyUnPackFile', false);
        $comboMaxUrlLength = Helper::get($comboOptions, 'maxUrlLength', self::COMBO_MAX_URL_LENGTH);

        foreach($allUrls as $type => $urls){
            $urls = $allUrls[$type] = array_unique($urls);

            if(!$comboOptions){
                continue;
            }

            $finalUrls = array();
            $combos = array();

            foreach($urls as $url){
                if($info = Helper::get($this->urlCache, $url)){
                    if($comboOnlyUnPackFile && !isset($info['isPkg']) || !$comboOnlyUnPackFile){
                        $combos[] = $url;
                    }else{
                        $finalUrls[] = $url;
                    }
                }else{
                    $finalUrls[] = $url;
                }
            }

            $combosDirGroup = array();

            foreach($combos as $url){
                preg_match('#(?:^(?:(?:https?:)?//)?[^/]+)?/#', $url, $data);
                $baseurl = $data[0];
                $combosDirGroup[$baseurl][] = $url;
            }

            foreach($combosDirGroup as $dir => $urls){
                if(count($urls) > 1){
                    $baseNames = array();
                    $dirLength = strlen($dir);
                    $len = 0;

                    foreach($urls as $url){
                        $url = substr($url, $dirLength);
                        $baseNames[] = $url;

                        if(strlen($url) + $len >= $comboMaxUrlLength){
                            $len = 0;
                            $resources[] = $dir . $comboOptions['syntax'][0] . implode($comboOptions['syntax'][1], $baseNames); 
                            $baseNames = array();
                        }else{
                            $len += strlen($url);
                        }
                    }

                    if(count($baseNames)){
                        $finalUrls[] = $dir . $comboOptions['syntax'][0] . implode($comboOptions['syntax'][1], $baseNames); 
                    }
                }else{
                    $finalUrls[] = $urls[0];
                } 
            }

            $allUrls[$type] = $finalUrls;
        }

        return $allUrls;
    }

    private function getRequireInfo($mapInfo){
        $requireInfo = $this->getUrls(Helper::get($mapInfo, 'asyncs', array()), true);
        $requireMaps = array();
        $requireDeps = array();

        foreach($requireInfo as $id => $url){
            $requireMaps[$url][] = $id;
            $info = $this->maps->get($id);

            if($deps = Helper::get($info, 'deps')){
                $requireDeps[$id] = $deps;
            }
        }

        foreach($requireMaps as $url => $ids){
            $requireMaps[$url] = array_values(array_unique($ids));
        }

        return array(
            'deps' => $requireDeps,
            'map' => $requireMaps
        );
    }

    public function getResourcesInfo($id){
        $mapInfo = $this->maps->getIncludeRefs($id);
        $isPagelet = Helper::get($this->maps->get($id), 'isPagelet', false);
        $pageletAsyncs = array();

        if($isPagelet){
            foreach(self::$RESOURCE_TYPES as $type){
                $pageletAsyncs = array_merge($pageletAsyncs, Helper::get($mapInfo, $type, array()));
                $mapInfo[$type] = array();
            }   

            $mapInfo['asyncs'] = array_merge(Helper::get($mapInfo, 'asyncs', array()), $pageletAsyncs);
        }

        $asyncs = Helper::get($mapInfo, 'asyncs', array());

        if(count($asyncs) && !$isPagelet && !Helper::get($mapInfo, 'isWidget')){
            if(Helper::get($mapInfo, 'headJs')){
                array_unshift($mapInfo['headJs'], self::LOADER);
            }else{
                $mapInfo['headJs'] = array(self::LOADER);
            }   
        }

        $threeUrls = $this->getThreeUrls($mapInfo);
        $requireInfo = $this->getRequireInfo($mapInfo);

        return array(   
            'pageletAsyncs' => $pageletAsyncs,
            'threeUrls' => $threeUrls,
            'requires' => $requireInfo
        );
    }

    public function getResourcesData($id){
        $cacheKey = implode('|', $this->templateDir) . $id;

        if($this->cache && ($data = $this->cache->get($cacheKey))){
            if($data['MAP_FILES_MAX_LAST_MODIFY_TIME'] == $this->maps->getLastModifyTime()){
                return $data;
            }
        }

        $info = $this->getResourcesInfo($id);

        $headJsInline = array();

        if(!empty($info['requires']) && !empty($info['requires']['map'])){
            $headJsInline[] = '<script>require.config(' . Helper::jsonEncode($info['requires']) . ')</script>';
        }

        $data = array(
            'MAP_FILES_MAX_LAST_MODIFY_TIME' => $this->maps->getLastModifyTime(),
            'FEATHER_HEAD_RESOURCE_LOADED' => false,
            'FEATHER_BOTTOM_RESOURCE_LOADED' => false,
            'FEATHER_USE_HEAD_SCRIPTS' => $info['threeUrls']['headJs'],
            'FEATHER_USE_HEAD_INLINE_SCRIPTS' => $headJsInline,
            'FEATHER_USE_SCRIPTS' => $info['threeUrls']['bottomJs'],
            'FEATHER_USE_STYLES' => $info['threeUrls']['css'],
            'PAGELET_ASYNCS' => $info['pageletAsyncs'],
            'FILE_PATH' => $id,
            'FILE_ID' => $id
        );

        $this->cache && $this->cache->set($cacheKey, $data);
        return $data;
    }
}