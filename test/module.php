<?php

namespace modules\video\test;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use diversen\conf;
use diversen\sendfile;
use diversen\html\video;

class module {

    
    public function testAction () {

        $adapter = new Local(conf::pathFiles());
        
        //$test = 
        $filesystem = new Filesystem($adapter);
        
        $file = '/video/reference/10';
        $res = $filesystem->put($file, 'contents');
        var_dump($res);
        echo $contents = $filesystem->read($file);
        
        
    }
    
    public function videoAction () {
        
        $v = new video();
        $v->videojsInclude();
        $formats = [];
        $formats['mp4'] = '/video/test/send';
        echo $v->getVideojsHtml('', $formats);
        
    }
    
    public function sendAction (){
        $s = new sendfile();
        $s->throttle(0.1, 40960);
        $s->send('/datadrive/movie.mp4', false);
    }
}
