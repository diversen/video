<?php

namespace modules\video;

use modules\video\module as video;

/**
 * Class that gets image blobs total size
 */
class size {
   
    /**
     * Get total blobs total size from a parent_id,
     * 
     * @param type $parent
     * @return type
     */
    public function getFilesSizeFromParentId ($reference, $parent) {

        $i = new video();
        $options = array(
                    'reference' => $reference, 
                    'parent_id' => $parent);

        $rows = $i->getAllVideoInfo($options
                , 2);
        $rows = $i->attachVideoLinks($rows, $reference, $parent);
        
        $bytes = 0;
        foreach($rows as $row) {
            
            if (file_exists($row['path'])) {
                $size = filesize($row['path']);
                if ($size) {
                    $bytes+=$size;
                }
            }
        }
        
        return $bytes;   
    }
}
