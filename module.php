<?php

namespace modules\video;

use diversen\bgJob;
use diversen\conf;
use diversen\db;
use diversen\db\admin;
use diversen\db\q;
use diversen\file;
use diversen\html;
use diversen\http;
use diversen\lang;
use diversen\log;
use diversen\uri;
use diversen\moduleloader;
use diversen\session;
use diversen\template;
use diversen\upload;
use diversen\uri\manip;
use diversen\user;
use diversen\strings;
use diversen\layout;

// use \modules\video\config;


/**
 * Module for adding and scaling videos. 
 */
class module {

        /**
     * Var holding errors
     * @var array 
     */
    public $errors = array();
    
    /**
     * Default max size upload in bytes
     * @var int 
     */
    public $maxsize = 2000000;
    
    /**
     * Var holding options
     * @var array
     */
    public $options = array();
    
    /**
     * Image base path
     * @var string
     */
    public $path = '/video';
    
    /**
     * Image base table
     * @var string
     */
    public $fileTable = 'video';
    
    /**
     * Var holding upload status
     * @var type 
     */
    public  $status = null;
  
    /**
     * Get options from $_GET
     * @return array $options ['parent_id', 'return_url', 'reference', 'query']
     */
    public function getOptions() {
        
        // Check for sane options
        if (!isset($_GET['parent_id'], $_GET['return_url'], $_GET['reference'] )) { 
            return false;
        }
        
        $options = array
            ('parent_id' => $_GET['parent_id'],
            'return_url' => $_GET['return_url'],
            'reference' => $_GET['reference'],
            'query' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY));
        return $options;
    }
    
    /**
     * Test action for getting total blob size of all images 
     * from a parent_id
     */
    public function sizeAction () {
        
        moduleloader::setModuleIniSettings('content');
        $parent = uri::fragment(2);
        $s = new \modules\video\size();
        $reference = conf::getModuleIni('content_parent');
        echo $s->getFilesSizeFromParentId($reference, $parent);
    }

    
        /**
     * set a headline and page title based on action
     * @param string $action 'add', 'edit', 'delete'
     */
    public  function setHeadlineTitle ($action = '') {

        $options = $this->getOptions();
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
     * Check access to module based on options and ini settings and action param
     * @param string $action 'add', 'edit', 'delete' (NOT used yet)
     * @return boolean $res true if allowed else false
     */
    public function checkAccess ($action = 'edit') {
        
        // Options used ['parent_id', 'reference']
        $options = $this->getOptions();
        if (!$options) {
            return false;
        }
    
        // Admin user is always allowed
        if (session::isAdmin()) {
            return true;
        }
        
        // Who is allowed - e.g. user or admin 
        // If 'admin' then only admin users can add images
        $allow = conf::getModuleIni('video_allow_edit');
        if (!session::checkAccessClean($allow)) {
            return false;
        }

        // Fine tuning of access can be set in image/config.php
        if (method_exists('modules\video\config', 'checkAccessParentId')) {
            $check = new \modules\video\config();
            return $check->checkAccessParentId($options['parent_id'], $action);
        }
        
        
        // If allow is set to user - this module only allow user to edit the images
        // he owns - based on 'reference' and 'parent_id'
        if ($allow == 'user') {
            if (!admin::tableExists($options['reference'])) {
                return false;
            }
            if (!user::ownID($options['reference'], $options['parent_id'], session::getUserId())) {
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

        // Check access
        if (!$this->checkAccess('edit')) {
            moduleloader::setStatus(403);
            return false;
        }
        
        // Options ARE sane now
        $options = $this->getOptions();
        layout::setMenuFromClassPath($options['reference']);
        
        $this->setHeadlineTitle('add');

        $this->viewInsert();
        $options['admin'] = true;
        $rows = $this->getAllvideoInfo($options, 2);
        
        if (!empty($rows)) {
            echo html::getHeadline(lang::translate('Uploaded videos'), 'h3');
            echo $this->displayAllVideo($rows, $options);
        }
        
        $rows = $this->getAllvideoInfo($options, 1);
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

        // Check access
        if (!$this->checkAccess('edit')) {
            moduleloader::setStatus(403);
            return false;
        }
        
        $options = $this->getOptions();
        layout::setMenuFromClassPath($options['reference']);
        
        $id = uri::fragment(2);
        $this->setHeadlineTitle('delete');
        $this->viewDelete($id);
    }

    /** 
     * Action for editing a video
     * @return void
     */
    public function editAction() {

        // Check access
        if (!$this->checkAccess('edit')) {
            moduleloader::setStatus(403);
            return false;
        }
        
        $options = $this->getOptions();
        layout::setMenuFromClassPath($options['reference']);

        $this->setHeadlineTitle('edit');
        $this->viewUpdate();
    }

    /**
     * constructor sets init vars
     */
    public function __construct($options = null) {
        moduleloader::includeModule('image');
        $this->options = $options;
      
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

        $f->formStartAry();

        $legend = '';
        if (isset($id)) {
            $values = $this->getSingleFileInfo($id);

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
        $options = $this->getOptions();
        
        $values['title'] = $title;
        $values['parent_id'] = $options['parent_id'];
        $values['reference'] = $options['reference'];
        $values['user_id'] = session::getUserId();
        $values['abstract'] = html::specialDecode($_POST['abstract']);
        $values['mimetype'] = $file['type'];
        $values['status'] = 1; // 1 = Transform in progress
        $res = $db->insert($this->fileTable, $values);
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
    public   function includePlayer() {
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
        
        $rows = $this->getAllVideoInfo(
                array(
                    'reference' => $reference, 
                    'parent_id' => $parent_id)
                );
        
        $rows = $this->attachVideoLinks($rows, $reference, $parent_id);
        $videos = array ('videos' => $rows);
        echo json_encode($videos);
        die;
    }
    
    /**
     * Attach full paths, mp4 links, and sanitize abstract
     * @param array $rows
     * @param string $reference
     * @param int $parent_id
     * @return array $rows
     */
    public function attachVideoLinks ($rows, $reference, $parent_id) {
        foreach ($rows as $key => $row) {
            $rows[$key] = $this->attachVideoLink($row, $parent_id, $reference);            
        }
        return $rows;
    }
    
    /**
     * Attach full path, mp4 link, and sanitize abstract
     * @param array $row
     * @param int $parent_id
     * @param string $reference
     * @return array $row
     */
    public function attachVideoLink($row, $parent_id, $reference) {
        $base = "/video/$reference/$parent_id/$row[title].mp4";
        $mp4 = conf::getWebFilesPath($base);
        $row['mp4'] = $mp4;
        $row['path'] = conf::pathFiles() . $base;

        $str = html::specialEncode($row['abstract']);
        $row['abstract'] = $str;
        return $row;
    }

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
        
        if ($prim_type == 'audio') {
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
            $this->errors = upload::$errors;
            return false;
        }

        $res = upload::checkMaxSize($file);
        if (!$res) {
            $this->errors = upload::$errors;
            return false;
        }

        $uniqid = md5(uniqid());
        if (!$this->isAllowedMime($file['tmp_name'])) {
            $this->errors[] = lang::translate('Content-type is not allowed');
            return false;
        }
        
        $res = move_uploaded_file($file['tmp_name'], sys_get_temp_dir() . "/" . $uniqid);
        if ($res) {
            //unlink($file['tmp_name']);
            return $this->insertFileDb($uniqid, $file);
        }
        return false;
    }


    /**
     * Start a background job based on filename and file type. 
     * @param string $filename
     * @param string $type
     */
    public function startBgJob($filename, $type = 'flv') {
        $bg = new bgJob();
        
        // Tmp file
        $full_from  =  sys_get_temp_dir() . "/" . $filename;
        
        $options = $this->getOptions();
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
        $p1 = (int) $this->getProgress($row, 'mp4');

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
    public  function getProgress($row, $type) {
        
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
            $this->errors[] = lang::translate('No files were selected');
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
        $res = $db->delete($this->fileTable, 'id', $id);
        return $res;
    }
    
    /**
     * Delete all videos
     * @param int $id
     * @param string $reference
     */
    public function deleteAll ($id, $reference) {
        $rows = q::select($this->fileTable)->filter('parent_id =', $id)->condition('AND')->filter('reference =', $reference)->fetch();
        foreach($rows as $row) {
            $this->deleteFile($row['id']);
        }
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
    public  function subModuleAdminOptionAry($options) {
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
        $str = '<ul class="uk-list  uk-list-striped ">';
        foreach ($rows as $val) {
            
            $str.= '<li>';
            
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
            $str.= "</li>";
        }
        $str.='</ul>';
        return $str;
    }

    /**
     * method for getting all info connected to modules.
     *
     * @return array assoc rows of modules belonging to user
     */
    public  function getAllVideoInfo($options, $status = 2) {
        $db = new db();
        $search = array(
            'parent_id' => $options['parent_id'],
            'reference' => $options['reference'],
            'status' => $status
        );

        $rows = $db->selectAll($this->fileTable, null, $search, null, null, 'created', false);
        return $rows;
    }

    /**
     * method for getting a single video info. 
     * @param int $id
     * @return array $row with info 
     */
    public  function getSingleFileInfo($id) {

        $db = new db();
        $search = array(
            'id' => $id
        );

        $fields = array('id', 'parent_id', 'title', 'abstract', 'published', 'created', 'reference');
        $row = $db->selectOne($this->fileTable, null, $search, $fields, null, 'created', false);
        return $row;
    }

    /**
     * method for fetching one file
     *
     * @return array row with selected video info
     */
    public  function getFile($id) {
        $db = new db();
        $row = $db->selectOne($this->fileTable, 'id', $id);
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

        $res = $db->update($this->fileTable, $values, uri::fragment(2));
        return $res;
    }

    /**
     * method to be used in a insert controller
     */
    public function viewInsert() {

        $options = $this->getOptions();
        
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
            if (empty($this->errors)) {
                
                // Get array with info about the uploaded files
                $files = $this->getUploadedFilesArray();  
                $res = $this->insertAll($files);
                
                if (!$res) {
                    echo html::getErrors($this->errors);
                } else {
                    $id_str = $this->getUploadIdsAsStr($res);
                    $redirect = $_SERVER['REQUEST_URI'] . "&$id_str";
                    http::locationHeader(
                            $redirect);
                }
            } else {
                echo html::getErrors($this->errors);
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

        $options = $this->getOptions();
        $redirect = $this->getRedirectVideoMain($options);
        
        if (isset($_POST['submit'])) {
            if (empty($this->errors)) {
                $res = $this->deleteFile($id);
                if ($res) { 
                    http::locationHeader($redirect, lang::translate('Video was deleted'));
                }
            } else {
                echo html::getErrors($this->errors);
            }
        }
        echo $this->formDelete();
        return;
    }
    
    /**
     * Delete form
     * @return string $html
     */
    public function formDelete() {
                
        $f = new html();
        $f->formStart('file_upload_form');
        $legend = lang::translate('Delete video');
        $f->legend($legend);
        $f->submit('submit', lang::translate('Delete'));
        $f->formEnd();
        return $f->getStr();
    }
    
    public  function getRedirectVideoMain ($options) {
        $url = "/video/add/?$options[query]";
        return $url;
    }

    /**
     * merhod to be used in an update controller 
     */
    public function viewUpdate() {

        $options = $this->getOptions();
        if (isset($_POST['submit'])) {
            if (empty($this->errors)) {
                $res = $this->updateFile();
                if ($res) {
                    $redirect = $this->getRedirectVideoMain($options);
                    http::locationHeader($redirect, lang::translate('Video was edited'));
                } else {
                    echo html::getErrors($this->errors);
                }
            } else {
                echo html::getErrors($this->errors);
            }
        }
        $this->formInsert('update', uri::fragment(2));
    }
}
