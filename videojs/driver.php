<?php

use diversen\template\assets;
use diversen\conf;

function video_player_include () {
    
    static $loaded = null;
        $css = <<<EOF
<style>
.video-js {padding-top: 56.25%}
.vjs-fullscreen {padding-top: 0px}
</style>
EOF;
    if (!$loaded) { 
        assets::setJs("http://vjs.zencdn.net/4.12/video.js");
        assets::setCss('http://vjs.zencdn.net/4.12/video-js.css');
        assets::setStringCss($css, null, array ('head' => true));
        $loaded = true;
        return $css;
    }  
}



function video_player_get_html ($row) {

    // poster="really-cool-video-poster.jpg"
    $base_path = conf::getWebFilesPath() . "/video/$row[reference]/$row[parent_id]/$row[title]";
    $flv = $base_path . ".flv";
    $mp4 = $base_path . ".mp4";
    $webm = $base_path . ".webm";
    $str = <<<EOF
<div class="wrapper">
 <div class="videocontent">
	
<video id="really-cool-video" class="video-js vjs-default-skin" controls
 preload="auto"  width="auto" height="auto"
 data-setup='{}'>
  <source type="video/mp4" src="$mp4">  
  <!--<source type="video/flv" src="$flv"> 
  <source type="video/webm" src="$webm">-->
  <p class="vjs-no-js">
    To view this video please enable JavaScript, and consider upgrading to a web browser
    that <a href="http://videojs.com/html5-video-support/" target="_blank">supports HTML5 video</a>
  </p>
</video>
  </div>
</div>
EOF;
        return $str;
}



function video_player_get_html5 ($row) {

    // poster="really-cool-video-poster.jpg"
    $base_path = conf::getFullFilesPath() . "/video/$row[reference]/$row[parent_id]/$row[title]";
    //$flv = $base_path . ".flv";
    $mp4 = $base_path . ".mp4";
    //$webm = $base_path . ".webm";
    $str = <<<EOF
<video controls="controls"> 
   <source src="$mp4" type="video/mp4" /> 
   <p> 
      The videos can not be played back on your system.<br/> 
      Try viewing on Youtube (requires Internet connection): 
      <a href="http://youtu.be/etE5urBps_w">Relative Velocity on Youtube</a>. 
   </p> 
</video>
EOF;
        return $str;
}
