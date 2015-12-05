<?php

namespace modules\video;
/**
 * model file for doing file uploads
 *
 * @package     content
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
use diversen\html\video;

/**
 * class content video is used for keeping track of file changes
 * in db. Uses object fileUpload
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

        //$video = new self($options);
        $this->viewFileFormInsert();

        $options['admin'] = true;
        
        
        $rows = self::getAllvideoInfo($options, 2);
        
        if (!empty($rows)) {
            echo html::getHeadline('Uploaded videos', 'h3');
            echo $this->displayAllVideo($rows, $options);
        }
        
        $rows = self::getAllvideoInfo($options, 1);
        if (!empty($rows)) {
            echo html::getHeadline('Videos under progress', 'h3');
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

        layout::setMenuFromClassPath($options['reference']);
        self::setHeadlineTitle('delete');
        
        $this->viewFileFormDelete($id);
    }

    public function editAction() {

        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }

        self::setHeadlineTitle('edit');
        $this->viewFileFormUpdate();
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
    public function viewFileForm($method, $id = null, $values = array(), $caption = null) {

        
        $f =  new html();
        $values = html::specialEncode($values);
        
        $f->formStart('file_upload_form');


        $legend = '';
        if (isset($id)) {
            $values = self::getSingleFileInfo($id);

            $f->init($values, 'submit');
            $legend = lang::translate('Edit video');
            $submit = lang::translate('Update video');

            $f->legend($legend);
            $f->label('abstract', lang::translate('Abstract'));
            $f->textareaSmall('abstract');
        } else {

            $f->init($_POST, 'submit', true);
            $legend = lang::translate('Add video');
            $f->label('abstract', lang::translate('Abstract'));
            $f->textareaSmall('abstract');
            $submit = lang::translate('Add video');

            $bytes = conf::getModuleIni('video_max_size');
            $f->fileWithLabel('file', $bytes);
        }







        $f->submit('submit', $submit);
        $f->formEnd();
        echo $f->getStr();
        return;
    }

    /**
     * method for inserting a module into the database
     * (access control is cheched in controller file)
     *
     * @return boolean true on success or false on failure
     */
    public function insertFile($title) {

        $values = db::prepareToPost();
        $db = new db();
        
        $db->begin();

        $options = self::getOptions();
        
        $values['title'] = $title;
        $values['parent_id'] = $options['parent_id'];
        $values['reference'] = $options['reference'];
        $values['user_id'] = session::getUserId();
        $values['abstract'] = html::specialDecode($_POST['abstract']);
        $values['mimetype'] = $_FILES['file']['type'];
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
        //$str = video_player_include();
        $info = self::getAllVideoInfo($options);
        $str = '';
        foreach ($info as $video) {
            
            $str.= "<hr />";
            $str.= video_player_get_html5($video);
            //$str.= "<hr />";
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
     * Upload a video
     * @return boolean
     */
    function uploadVideo() {

        // upload options
        $options['maxsize'] = conf::getModuleIni('video_max_size');
        upload::setOptions($options);
        
        $res = upload::checkUploadNative('file');
        if (!$res) {
            self::$errors = upload::$errors;
            return false;
        }

        $res = upload::checkMaxSize('file');
        if (!$res) {
            self::$errors = upload::$errors;
            return false;
        }

        $uniqid = uniqid();
        $res = copy($_FILES['file']['tmp_name'], sys_get_temp_dir() . "/" . $uniqid);
        
        if (!$this->isAllowedMime($_FILES['file']['tmp_name'])) {
            self::$errors[] = lang::translate('Content-type is not allowed');
            return false;
        }
        
        if ($res) {
            return $this->insertFile($uniqid);
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
        
        $options = self::getOptions();
        $base = "/video/$options[reference]/$options[parent_id]";

        file::mkdir($base);
        
        $full_to = conf::getFullFilesPath($base . "/$filename.$type"); 
        $output_file = conf::getFullFilesPath($base) . "/$filename.$type.output";
        $pid_file = conf::getFullFilesPath($base) . "/$filename.$type.pid";
        
        // if ($type == 'webm') {
            // $command = "ffmpeg -i $full_from -c:v libx264 c:a libvorbis copy $full_to";
        //    $command = "ffmpeg -i $full_from -vcodec libvpx -acodec libvorbis $full_to";
            //$command = "ffmpeg -i $full_from -c:v libvpx -b:v 1M -c:a libvorbis $full_to";
        //} else {
        //$command = "ffmpeg -i $full_from -s 640x480 -vcodec mpeg4 -b 4000000 -acodec libmp3lame -ab 192000 $full_to";
        
        $width = conf::getModuleIni('video_scale_width');
        if (!$width ) {
            $width = '720';
        }
        $command = "ffmpeg -i $full_from -vf 'scale=$width:trunc(ow/a/2)*2' -c:v libx264  $full_to";
         //$command = "ffmpeg -i $full_from -s 640x480 -c:v libx264  $full_to";
        // -vf "scale=640:-1" 
        
        //}// -c:a copy

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
        // $p2 = (int) self::getProgress($row, 'flv');
        // $p3 = (int) self::getProgress($row, 'webm');
        
        // $total = ($p1 + $p2 + $p3) / 3;
        $total = $p1;
        
        $total = (int)$total;
        
        // Done when progress == 100
        if ($total == 100) {
            //log::error($id);
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
    public function testAction ($id) { 
        //$id = $_GET['id'];
        
        ?>
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
}, 2000); // 5 seconds
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
        if (empty($_FILES['file']['name'])) {
            self::$errors[] = lang::translate('No file was specified');
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
            //unlink($row['full_path']); //filename)
            //unlink($row['full_path_mp4']);
            unlink($base . ".mp4");
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
            //print_r($options);
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
    public function viewFileFormInsert() {

        $options = self::getOptions();
        
        
        
        if (isset($_GET['id']) && file_exists(sys_get_temp_dir() . "/" . $_GET['id']) ) {
            $uniqid = $_GET['id'];
            
            $row = q::select('video')->filter('title =', $_GET['id'])->fetchSingle();
            //print_r($row); die;
            
            // $this->startBgJob($uniqid, 'flv');
            $this->startBgJob($uniqid, 'mp4');
            
            
            //if ($row['mimetype'] != 'video/webm') { 
            // $this->startBgJob($uniqid, 'webm');
            //}
            $redirect = manip::deleteQueryPart($_SERVER['REQUEST_URI'], 'id');
            http::locationHeader(
                            $redirect, 
                            lang::translate('Video was uploaded, and it is now being transformed. You may move away, and return to see the progress')
                    );
            
        }
        
        $ary = array(
                'parent_id =' => $options['parent_id'],
                'reference =' => $options['reference'],
                'status =' => 1);
        
        $rows = q::select('video')->filterArray($ary)->fetch();
        
        foreach ($rows as $row) {
            $this->testAction($row['title']);
        }
        

        if (isset($_POST['submit'])) {
            $this->validateInsert();
            if (!isset(self::$errors)) {
                
                // Copy the video, insert into DB and return a unique video id
                $res = $this->uploadVideo();
                
                if ($res) {
                    $redirect = $_SERVER['REQUEST_URI'] . "&id=$res";
                    http::locationHeader(
                            $redirect);
                } else {
                    html::errors(self::$errors);
                }
            } else {
                html::errors(self::$errors);
            }
        }
        $this->viewFileForm('insert');
    }


    /**
     * method to be used in a delete controller
     */
    public function viewFileFormDelete($id) {


        
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
    public function viewFileFormUpdate() {

        $options = self::getOptions();
        if (isset($_POST['submit'])) {
            if (!isset(self::$errors)) {
                $res = $this->updateFile();
                if ($res) {
                    $redirect = self::getRedirectVideoMain($options);
                    http::locationHeader($redirect, lang::translate('Video was edited'));
                } else {
                    html::errors(self::$errors);
                }
            } else {
                html::errors(self::$errors);
            }
        }
        $this->viewFileForm('update', uri::fragment(2));
    }

}
