<?php

use diversen\template;
use diversen\template\assets;

function video_player_include() {

    static $loaded = null;

    if (!$loaded) {
        template::init('flowplayer');
        assets::setJsHead("http://releases.flowplayer.org/5.5.2/flowplayer.min.js");
        assets::setCss('http://releases.flowplayer.org/5.5.2/skin/minimalist.css');
        $loaded = true;
    }

    $str = '';
    $str.= <<<EOF
   <script>
    flowplayer.conf={
        engine:'flash'
    };
</script>
EOF;
    return  $str;
}

function video_player_get_html($row) {

    $str = <<<EOF
<div class="flowplayer">
   <video>
    <source type="video/mp4" src="$row[web_path_mp4]">  
    <source type="video/flv" src="$row[web_path]">        
   </video>
</div>
EOF;
    return $str;
}