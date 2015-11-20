<?php

namespace modules\video;

use Cron\CronExpression;

class cron {
    
    public static function run() {

        $minute = CronExpression::factory('* * * * *');
        if ($minute->isDue()) {
            self::checkUploads();
        } 
    }
    
    public static function checkUploads () {
        echo "Is due: Hello world\n";
    } 
}
