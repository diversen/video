<?php

namespace modules\video;

use Cron\CronExpression;

class cron {
    
    public function run() {

        $minute = CronExpression::factory('* * * * *');
        if ($minute->isDue()) {
            $this->checkUploads();
        } 
    }
    
    public function checkUploads () {
        // echo "Is due: Hello world\n";
    } 
}
