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
    const THIRD_REG = '#(?:^|:)static/(?:.+?/)*third/#';
    const DOMAIN_REG = '#(?:^(?:(?:https?:)?//)?[^/]+)?/#';

    public function __construct($templateDir, $options = array()){
        $this->templateDir = (array)$templateDir;
        $this->options = $options;

        $mapDirs = array();

        foreach($this->templateDir as $dir){
            $mapDirs[] = $dir . '/_map_';
        }

        if(($cacheDir = Helper::get($this->options, 'cacheDir')) && Helper::get($this->options, 'cache')){
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

            $combos = array();
            $i = 0;

            foreach($urls as $url){
                if($info = Helper::get($this->urlCache, $url)){
                    if(($comboOnlyUnPackFile && !isset($info['isPkg']) || !$comboOnlyUnPackFile)
                        && !self::isThird($info['id'])
                    ){
                        $combos[$i][] = $url;
                    }else{
                        $combos[] = $url;
                        $i = count($combos);
                    }
                }else{
                    $combos[] = $url;
                    $i = count($combos);
                }
            }

            $finalUrls = array();

            foreach($combos as $urls){
                if(is_string($urls)){
                    $finalUrls[] = $urls;
                    continue;
                }else if(count($urls) == 1){
                    $finalUrls[] = $urls[0];
                    continue;
                }

                $dir = null;
                $len = 0;
                $dirLen = 0;
                $bases = array();

                foreach($urls as $url){
                    $domain = self::getDomain($url);

                    if(!$dir){
                        $dir = $domain;
                    }

                    if($domain != $dir || $len >= $comboMaxUrlLength){
                        if(count($bases) > 1){
                            $finalUrls[] = $dir . $comboOptions['syntax'][0] . implode($comboOptions['syntax'][1], $bases);
                        }else if(count($bases) == 1){
                            $finalUrls[] = $dir . $bases[0];
                        }

                        $bases = array();
                        $dir = $domain;
                    }

                    $dirLen = strlen($domain);
                    $base = substr($url, $dirLen);

                    $bases[] = $base;
                    $len += strlen($base);
                }

                if(count($bases) > 1){
                    $finalUrls[] = $dir . $comboOptions['syntax'][0] . implode($comboOptions['syntax'][1], $bases);
                }else if(count($bases) == 1){
                    $finalUrls[] = $dir . $bases[0];
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

        if(count($asyncs) && (Helper::get($this->options, 'debug') || !$isPagelet)){
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

    public static function isThird($id){
        return !!preg_match(self::THIRD_REG, $id, $match);
    }

    public static function getDomain($url){
        preg_match(self::DOMAIN_REG, $url, $data);
        return $data[0];
    }
}