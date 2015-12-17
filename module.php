<?php

namespace modules\video;

/**
 * Module for scaling uploading and scaling videos. 
 * @package     video
 */

/**
 * @ignore
 */

use diversen\bgJob;
use diversen\conf;
use diversen\db;
use diversen\db\admin;
use diversen\db\q;
use diversen\file;
use diversen\html;
use diversen\http;
use diversen\lang;
use diversen\layout;
use diversen\log;
use diversen\uri;
use diversen\moduleloader;
use diversen\session;
use diversen\template;
use diversen\upload;
use diversen\uri\manip;
use diversen\user;
use diversen\strings;

use modules\video\config;


/**
 * Module for adding and scaling videos. 
 */
class module {

    public static $errors = null;
    public static $status = null;
    public static $parent_id;
    public static $fileId;
    public static $allow;
    public static $maxsize = 2000000; // 2 mb max size
    public static $options = array();
    public static $fileTable = 'video';

    
    /**
     * get options from QUERY
     * @return array $options
     */
    public static function getOptions() {
        $options = array
            ('parent_id' => $_GET['parent_id'],
            'return_url' => $_GET['return_url'],
            'reference' => $_GET['reference'],
            'query' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY)
            );
        return $options;
    }
    
        /**
     * set a headline and page title based on action
     * @param string $action 'add', 'edit', 'delete'
     */
    public static function setHeadlineTitle ($action = '') {

        $options = self::getOptions();
        if ($action == 'add') {
            $title = lang::translate('Add video');
        }
        
        if ($action == 'edit') {
            $title = lang::translate('Edit video');
        }
        
        if ($action == 'delete') {
            $title = lang::translate('Delete video');
        }
            
        // set headline and title
        $headline = $title . MENU_SUB_SEPARATOR_SEC;
        $headline.= html::createLink($options['return_url'], lang::translate('Go back'));

        echo html::getHeadline($headline);
        template::setTitle($title);
    }
    
        /**
     * check access to module based on options and blog ini settings 
     * @param array $options
     * @return void
     */
    public static function checkAccess ($options) {
        
        // check access
        if (!session::checkAccessClean(self::$allow)) {
            return false;
        }

        // if allow is set to user - this module only allow user to edit his images
        // to references and parent_ids which he owns
        if (self::$allow == 'user') {
            
            $table = moduleloader::moduleReferenceToTable($options['reference']);
            if (!admin::tableExists($table)) {
                return false;
            }
            if (!user::ownID($table, $options['parent_id'], session::getUserId())) {
                moduleloader::setStatus(403);
                return false;
            }
        }
        return true;
    }
    
        /**
     * Get uploaded files as a organized array
     * @return array $ary
     */
    public function getUploadedFilesArray () {
                
        $input ='files';
        
        $ary = array ();
        foreach ($_FILES[$input]['name'] as $key => $name) {
            $ary[$key]['name'] = $name;
        }
        foreach ($_FILES[$input]['type'] as $key => $type) {
            $ary[$key]['type'] = $type;
        }
        foreach ($_FILES[$input]['tmp_name'] as $key => $tmp_name) {
            $ary[$key]['tmp_name'] = $tmp_name;
        }
        foreach ($_FILES[$input]['error'] as $key => $error) {
            $ary[$key]['error'] = $error;
        }
        foreach ($_FILES[$input]['size'] as $key => $size) {
            $ary[$key]['size'] = $size;
        }
        return $ary;
    }
      
    /**
     * Main action. Add a video.
     * @return boolean
     */
    public function addAction() {

        if (!isset($_GET['parent_id'], $_GET['return_url'], $_GET['reference'] )) { 
            moduleloader::setStatus(403);
            return false;
        }
        
        // get options from QUERY
        $options = self::getOptions();
        
        if (!self::checkAccess($options)) {
            moduleloader::setStatus(403);
            return false;
        }
        
        self::setHeadlineTitle('add');
        layout::setMenuFromClassPath($options['reference']);

        $this->viewInsert();
        $options['admin'] = true;
        $rows = self::getAllvideoInfo($options, 2);
        
        if (!empty($rows)) {
            echo html::getHeadline(lang::translate('Uploaded videos'), 'h3');
            echo $this->displayAllVideo($rows, $options);
        }
        
        $rows = self::getAllvideoInfo($options, 1);
        if (!empty($rows)) {
            echo html::getHeadline(lang::translate('Videos under progress'), 'h3');
            echo $this->displayAllVideo($rows, $options);
        }
    }

    /**
     * Action for deleting a video
     * @return type
     */
    public function deleteAction() {

        $id = uri::fragment(2);
        $options = self::getOptions();
        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }
        
        if (!self::checkAccess($options)) {
            moduleloader::setStatus(403);
            return false;
        }
        
        layout::setMenuFromClassPath($options['reference']);
        self::setHeadlineTitle('delete');
        
        $this->viewDelete($id);
    }

    /** 
     * Action for editing a video
     * @return void
     */
    public function editAction() {

        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }
        
        $options = self::getOptions();
        if (!self::checkAccess($options)) {
            moduleloader::setStatus(403);
            return false;
        }

        self::setHeadlineTitle('edit');
        $this->viewUpdate();
    }

    /**
     * constructor sets init vars
     */
    function __construct($options = null) {

        self::$options = $options;
        if (!isset($options['allow'])) {
            self::$allow = conf::getModuleIni('video_allow_edit');
        }
        
        if (!isset($options['maxsize'])) {
            $maxsize = conf::getModuleIni('video_max_size');
            if ($maxsize) {
                self::$options['maxsize'] = $maxsize;
            }
        }        
    }

    /**
     * method for creating a form for insert, update and deleting entries
     * in module_system module
     *
     *
     * @param string    method (update, delete or insert)
     * @param int       id (if delete or update)
     */
    public function formInsert($method, $id = null, $values = array(), $caption = null) {

        
        $f =  new html();
        $values = html::specialEncode($values);
        
        //$f->formStart('file_upload_form');
        $f->formStartAry(array ('onsubmit'=>"setFormSubmitting()"));

        $legend = '';
        if (isset($id)) {
            $values = self::getSingleFileInfo($id);

            $f->init($values, 'submit');
            $legend = lang::translate('Edit video');
            $submit = lang::translate('Update video');

            $f->legend($legend);
            $f->label('abstract', lang::translate('Title'));
            $f->textareaSmall('abstract');
        } else {

            $f->init($_POST, 'submit', true);
            $legend = lang::translate('Add video');
            
            $bytes = conf::getModuleIni('video_max_size');
            $options = array ('multiple' => 'multiple');
            //$options = array ();
            $f->fileWithLabel('files[]', $bytes, $options);
            
            $f->label('abstract', lang::translate('Title'));
            $f->textareaSmall('abstract');
            $submit = lang::translate('Add video');
        }

        $f->submit('submit', $submit);
        $f->formEnd();
        echo $f->getStr();
        return;
    }

    /**
     * Method for inserting a video into the database
     *
     * @return boolean|string $res uniq title string on success, false on failure
     */
    public function insertFileDb($title, $file) {

        $values = db::prepareToPost();
        $db = new db();
        
        $db->begin();
        $options = self::getOptions();
        
        $values['title'] = $title;
        $values['parent_id'] = $options['parent_id'];
        $values['reference'] = $options['reference'];
        $values['user_id'] = session::getUserId();
        $values['abstract'] = html::specialDecode($_POST['abstract']);
        $values['mimetype'] = $file['type'];
        $values['status'] = 1; // 1 = Transform in progress
        $res = $db->insert(self::$fileTable, $values);
        if ($res) {
            $db->commit();
            return $title;

        } else {
            $db->rollback();
        }

        return $res;
    }
    
    /**
     * Include video player based on configuration 
     */
    public static  function includePlayer() {
        if (!conf::getModuleIni('video_player')) {
            conf::setModuleIni('video_player', 'videojs');
        }
        include_once conf::getModuleIni('video_player') . "/driver.php";        
    }

    /**
     * rpc action for fetchng info about videeos based on a reference and
     * a parent_id.
     * echo a jason string containing the info. 
     * @return void
     */
    public function rpcAction () {
        $reference = @$_GET['reference'];
        $parent_id = @$_GET['parent_id'];
        
        if (empty($reference) || empty($parent_id)) {
            return;
        }
        
        $rows = self::getAllVideoInfo(
                array(
                    'reference' => $reference, 
                    'parent_id' => $parent_id)
                );
        foreach ($rows as $key => $val) {
            
            $base = "/video/$reference/$parent_id";
            $mp4 = conf::getWebFilesPath($base . "/$val[title].mp4");
            $rows[$key]['mp4'] = $mp4; //self::$path . "/download/$val[id]/" . strings::utf8SlugString($val['title']);
            //$rows[$key]['url_s'] = self::$path . "/download/$val[id]/" . strings::utf8SlugString($val['title']) . "?size=file_thumb";
            $str = strings::sanitizeUrlRigid(html::specialDecode($val['abstract']));
            $rows[$key]['abstract'] = $str;
            
        }
        
        $videos = array ('videos' => $rows);
        echo json_encode($videos);
        die;
    }
    
    /**
     * Get videos connected to a entity: 
     * 
     * @param type $options
     * @return type
     */
    public static function getVideoHtml5($options) {

        self::includePlayer();
        $info = self::getAllVideoInfo($options);
        $str = '';
        foreach ($info as $video) {
            
            $str.= "<hr />";
            $str.= video_player_get_html5($video);
        }
        return $str;
    }
    
    public static $player = 'html'; // Epub or HTML
    
    
    /**
     * Check if video mime is allowed. 
     * @param string $file
     * @return boolean $res true if allowed else false
     */
    public function isAllowedMime($file) {
        
        $prim_type = file::getPrimMime($file);
        if ($prim_type == 'video') {
            return true;
        }
        
        $mime = file::getMime($file);
        
        $ary = [];
        $ary[] = 'video/webm';
        $ary[] = 'application/ogg';
        $ary[] = 'video/ogg';
        $ary[] = 'video/mp4';

        if (in_array($mime, $ary)) {
            return true;
        } 
        return false;
    }
    
    /**
     * Insert all files
     * @param array $files
     * @return false|array array with file ids on success, false on failure. 
     */
    public function insertAll ($files) {
        $success = [];
        foreach($files as $file) {
            $res = $this->moveVideo($file);
            if ($res) {
                $success[] = $res;
            } else {
                return false;
            }
        }
        return $success;
    }
    
    /**
     * Upload a video
     * @return boolean
     */
    public function moveVideo($file) {

        // upload options
        $options['maxsize'] = conf::getModuleIni('video_max_size');
        upload::setOptions($options);
        
        $res = upload::checkUploadNative($file);
        if (!$res) {
            self::$errors = upload::$errors;
            return false;
        }

        $res = upload::checkMaxSize($file);
        if (!$res) {
            self::$errors = upload::$errors;
            return false;
        }

        $uniqid = uniqid();
        if (!$this->isAllowedMime($file['tmp_name'])) {
            self::$errors[] = lang::translate('Content-type is not allowed');
            return false;
        }
        
        
        $res = move_uploaded_file($file['tmp_name'], sys_get_temp_dir() . "/" . $uniqid);
        if ($res) {
            unlink($file['tmp_name']);
            return $this->insertFileDb($uniqid, $file);
        }
        return false;
    }
    
    
    public function uploadJs () { ?>
<script>
var formSubmitting = false;
var setFormSubmitting = function() { formSubmitting = true; };

window.onload = function() {
    window.addEventListener("beforeunload", function (e) {
        if (formSubmitting) {
            return undefined;
        }

        var confirmationMessage = 'It looks like you have been editing something. '
                                + 'If you leave before saving, your changes will be lost.';

        (e || window.event).returnValue = confirmationMessage; //Gecko + IE
        return confirmationMessage; //Gecko + Webkit, Safari, Chrome etc.
    });
};
</script>
    <?php }

    /**
     * Start a background job based on filename and file type. 
     * @param string $filename
     * @param string $type
     */
    public function startBgJob($filename, $type = 'flv') {
        $bg = new bgJob();
        
        // Tmp file
        $full_from  =  sys_get_temp_dir() . "/" . $filename;
        
        $options = self::getOptions();
        $base = "/video/$options[reference]/$options[parent_id]";

        file::mkdir($base);
        
        $full_to = conf::getFullFilesPath($base . "/$filename.$type"); 
        $output_file = conf::getFullFilesPath($base) . "/$filename.$type.output";
        $pid_file = conf::getFullFilesPath($base) . "/$filename.$type.pid";

        $width = conf::getModuleIni('video_scale_width');
        if (!$width ) {
            $width = '720';
        }
                
        $full_from = escapeshellarg($full_from);
        $command = "ffmpeg -i $full_from -vf 'scale=$width:trunc(ow/a/2)*2' -c:v libx264  $full_to";

        log::debug($command);
        $bg->execute($command, $output_file, $pid_file);

    }
    
    /**
     * Display progress of ffmpeg based on QUERY id and type
     */
    public function progressAction () {

        $id = $_GET['id'];            
        $row = q::select('video')->filter('title =', $id)->fetchSingle();
        $p1 = (int) self::getProgress($row, 'mp4');

        $total = $p1;        
        $total = (int)$total;
        if ($total == 100) {
            $row = q::select('video')->filter('title =', $id)->fetchSingle();
            if ($row['status'] == 1) {

                $ary = array('status' => '2');
                $res = q::update('video')->values($ary)->filter('title =', $id)->exec();
            }
        }
        
        echo $total;
        die();
    }
    
    /**
     * Get video transformation progress based on video row and type
     * @param array $row
     * @param string $type
     * @return int $progress e.g. 25
     */
    public static function getProgress($row, $type) {
        
        $output_file = "/video/$row[reference]/$row[parent_id]/$row[title].$type.output";
        $full_to = conf::getFullFilesPath($output_file);
        
        if (file_exists($full_to)) {
            $content = file_get_contents($full_to);
        } else {
            return;
        }

        if ($content) {
            //get duration of source
            preg_match("/Duration: (.*?), start:/", $content, $matches);

            $rawDuration = $matches[1];

            //rawDuration is in 00:00:00.00 format. This converts it to seconds.
            $ar = array_reverse(explode(":", $rawDuration));
            $duration = floatval($ar[0]);
            if (!empty($ar[1])) {
                $duration += intval($ar[1]) * 60;
            }
            if (!empty($ar[2])) {
                $duration += intval($ar[2]) * 60 * 60;
            }

            //get the time in the file that is already encoded
            preg_match_all("/time=(.*?) bitrate/", $content, $matches);

            $rawTime = array_pop($matches);

            //this is needed if there is more than one match
            if (is_array($rawTime)) {
                $rawTime = array_pop($rawTime);
            }

            //rawTime is in 00:00:00.00 format. This converts it to seconds.
            $ar = array_reverse(explode(":", $rawTime));
            $time = floatval($ar[0]);
            if (!empty($ar[1])) {
                $time += intval($ar[1]) * 60;
            }
            if (!empty($ar[2])) {
                $time += intval($ar[2]) * 60 * 60;
            }

            //calculate the progress
            $progress = round(($time / $duration) * 100);

            // echo "Duration: " . $duration . "<br>";
            // echo "Current Time: " . $time . "<br>";
            // echo "Progress: " . $progress . "%";
            return $progress;
        }
    }

    /**
     * 
     */
    public function jsProgressBar ($id) { ?>
<script>
setInterval(function(){
    $.get('/video/progress?id=<?=$id?>', function(data) { 
        //$('progress').attr('value', data);
        $('#<?=$id?> .uk-progress-bar').attr('style', 'width: ' + data + '%');
        $('#<?=$id?> .uk-progress-bar').html(data + '%');
        if (data == '100') {
            window.location.reload();
            //$('#<?=$id?>').hide();
        }
    });
}, 2000);
</script>
<div class="uk-progress" id ="<?=$id?>">
    <div class="uk-progress-bar"  style="width: 0%;">0%</div>
</div>
<?php
    }

    /**
     * method for validating a post before insert
     */
    public function validateInsert($mode = false) {
        if (empty($_FILES['files']['name']['0'])){
            self::$errors[] = lang::translate('No files were selected');
        }
    }

    /**
     * method for delting a file
     *
     * @param   int     id of file
     * @return  boolean true on success and false on failure
     *
     */
    public function deleteFile($id) {
        $row = $this->getSingleFileInfo($id);
        
        $base = conf::getFullFilesPath() . "/video/$row[reference]/$row[parent_id]/$row[title]";
        if (file_exists($base . ".mp4")) {
            unlink($base . ".mp4");
        }
        
        // Kill process if running
        $pid = $base . ".mp4.pid";
        if (file_exists($pid)) {
            $contents = trim(file_get_contents($pid));
            shell_exec("kill $contents");
            unlink($pid);
            unlink($base . ".mp4.output");
        }
        
        $db = new db();
        $res = $db->delete(self::$fileTable, 'id', $id);
        return $res;
    }

    /**
     * get admin when operating as a sub module
     * @param array $options
     * @return string  
     */
    public static function subModuleAdminOption ($options){        
        $url = "/video/add?" . http_build_query($options);
        $extra = array();
        if (isset($options['options'])) {
            $extra = $options['options'];
        }
        
        return html::createLink($url, lang::translate('Videos'), $extra); //$url;

    }

    /**
     * get admin options as ary ('text', 'url', 'link') when operating as a sub module
     * @param array $options
     * @return array $ary  
     */
    public static function subModuleAdminOptionAry($options) {
        $ary = array();
        $url = moduleloader::buildReferenceURL('/video/add', $options);
        $text = lang::translate('Add video');
        $ary['link'] = html::createLink($url, $text);
        $ary['url'] = $url;
        $ary['text'] = $text;
        return $ary;
    }

    /**
     * method for displaying all video. 
     * @param array $rows
     * @param array $options
     * @return string 
     */
    public function displayAllVideo($rows, $options) {
        $str = '';

        foreach ($rows as $val) {
            $title = lang::translate('Download video');
            $title.= MENU_SUB_SEPARATOR_SEC;
            $title.= $val['title'];

            $link_options = array('title' => $val['abstract']);

            /*
            $str.= html::createLink(
                            "$val[title]", $title, $link_options
            );
            */
            $val = html::specialEncode($val);
            $str.=$val['abstract'];
            // as a sub module the sub module can not know anything about the
            // id of individual video. That's why we will add id. 
            $options['id'] = $val['id'];
            if (isset($options['admin'])) {

                // edit and delete

                $edit = "/video/edit/$val[id]?" . $options['query'];
                $str.= MENU_SUB_SEPARATOR;
                $str.= html::createLink($edit, lang::translate('Edit'));
                
                
                $delete = "/video/delete/$val[id]?" . $options['query'];
                $str.= MENU_SUB_SEPARATOR;
                $str.= html::createLink($delete, lang::translate('Delete'));
            }
            $str.= "<hr />\n";
        }
        return $str;
    }

    /**
     * method for getting all info connected to modules.
     *
     * @return array assoc rows of modules belonging to user
     */
    public static function getAllVideoInfo($options, $status = 2) {
        $db = new db();
        $search = array(
            'parent_id' => $options['parent_id'],
            'reference' => $options['reference'],
            'status' => $status
        );

        $rows = $db->selectAll(self::$fileTable, null, $search, null, null, 'created', false);
        return $rows;
    }

    /**
     * method for getting a single video info. 
     * @param int $id
     * @return array $row with info 
     */
    public static function getSingleFileInfo($id = null) {
        if (!$id) {
            $id = self::$fileId;
        }
        $db = new db();
        $search = array(
            'id' => $id
        );

        $fields = array('id', 'parent_id', 'title', 'abstract', 'published', 'created', 'reference');
        $row = $db->selectOne(self::$fileTable, null, $search, $fields, null, 'created', false);
        return $row;
    }

    /**
     * method for fetching one file
     *
     * @return array row with selected video info
     */
    public static function getFile() {
        $db = new db();
        $row = $db->selectOne(self::$fileTable, 'id', self::$fileId);
        return $row;
    }

    /**
     * method for updating a module in database
     * (access control is cheched in controller file)
     *
     * @return boolean  true on success or false on failure
     */
    public function updateFile() {

        $values['abstract'] = html::specialDecode($_POST['abstract']);
        $db = new db();

        $res = $db->update(self::$fileTable, $values, uri::fragment(2));
        return $res;
    }

    /**
     * method to be used in a insert controller
     */
    public function viewInsert() {

        $options = self::getOptions();
        
        // If iset 'id', the upload has been done, and the transformation should begin
        if (isset($_GET['id']) && is_array($_GET['id'])) { // && file_exists(sys_get_temp_dir() . "/" . $_GET['id'])

            $ary = $_GET['id'];
            foreach ($ary as $id) {
                $row = q::select('video')->filter('title =', $id)->fetchSingle(); 
                $this->startBgJob($id, 'mp4');
            }
            
            $redirect = manip::deleteQueryPart($_SERVER['REQUEST_URI'], 'id');
            http::locationHeader(
                $redirect, 
                lang::translate('Video(s) uploaded. They are now being transformed. You may move away, and return to see the progress')
            );
        }
        
        $ary = array(
                'parent_id =' => $options['parent_id'],
                'reference =' => $options['reference'],
                'status =' => 1);
        
        $rows = q::select('video')->filterArray($ary)->fetch();
        
        foreach ($rows as $row) {
            $this->jsProgressBar($row['title']);
        }
        
        if (isset($_POST['submit'])) {
            // $this->uploadJs();
            $this->validateInsert();
            if (!isset(self::$errors)) {
                
                // Get array with info about the uploaded files
                $files = $this->getUploadedFilesArray();  
                $res = $this->insertAll($files);
                
                if (!$res) {
                    echo html::getErrors(self::$errors);
                } else {
                    $id_str = $this->getUploadIdsAsStr($res);
                    $redirect = $_SERVER['REQUEST_URI'] . "&$id_str";
                    http::locationHeader(
                            $redirect);
                }
            } else {
                echo html::getErrors(self::$errors);
            }
        }
        $this->formInsert('insert');
    }
    
    /**
     * Return files as an GET string with [] of ids
     * @param array $ids
     * @return string $str
     */
    public function getUploadIdsAsStr ($ids) {
        $str = '';
        foreach($ids as $id) {
            $str.= "id[]=$id&";
        }
        return $str;
    }


    /**
     * method to be used in a delete controller
     */
    public function viewDelete($id) {

        $options = self::getOptions();
        $redirect = self::getRedirectVideoMain($options);
        
        if (isset($_POST['submit'])) {
            if (!isset(self::$errors)) {
                $res = $this->deleteFile($id);
                if ($res) { 
                    http::locationHeader($redirect, lang::translate('Video was deleted'));
                }
            } else {
                html::errors(self::$errors);
            }
        }
        
        $f = new html();

        $f->formStart('file_upload_form');

        $legend = lang::translate('Delete video');
        $f->legend($legend);
        $f->submit('submit', lang::translate('Delete'));
        $f->formEnd();
        echo $f->getStr();

        return;
    }
    
    public static function getRedirectVideoMain ($options) {
        $url = "/video/add/?$options[query]";
        return $url;
    }

    /**
     * merhod to be used in an update controller 
     */
    public function viewUpdate() {

        $options = self::getOptions();
        if (isset($_POST['submit'])) {
            if (!isset(self::$errors)) {
                $res = $this->updateFile();
                if ($res) {
                    $redirect = self::getRedirectVideoMain($options);
                    http::locationHeader($redirect, lang::translate('Video was edited'));
                } else {
                    echo html::getErrors(self::$errors);
                }
            } else {
                echo html::getErrors(self::$errors);
            }
        }
        $this->formInsert('update', uri::fragment(2));
    }
}
