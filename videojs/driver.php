<?php

use diversen\template\assets;

function video_player_include () {
    
    static $loaded = null;

    if (!$loaded) {
        assets::setJs("http://vjs.zencdn.net/4.12/video.js");
        assets::setCss('http://vjs.zencdn.net/4.12/video-js.css');
        $loaded = true;
    }
}



function video_player_get_html ($row) {
    
    // poster="really-cool-video-poster.jpg"
        $str = <<<EOF
   
<video id="really-cool-video" class="video-js vjs-default-skin" controls
 preload="auto"  width="auto" height="auto" 
 data-setup='{}'>
  <source type="video/mp4" src="$row[web_path_mp4]">  
    <source type="video/flv" src="$row[web_path]">  
  <p class="vjs-no-js">
    To view this video please enable JavaScript, and consider upgrading to a web browser
    that <a href="http://videojs.com/html5-video-support/" target="_blank">supports HTML5 video</a>
  </p>
</video>
EOF;
        return $str;
}

