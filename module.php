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

use diversen\conf;
use diversen\db;
use diversen\file;
use diversen\html;
use diversen\http;
use diversen\lang;
use diversen\layout;
use diversen\moduleloader;
use diversen\session;
use diversen\template;
use diversen\upload;
use diversen\upload\blob;
use diversen\uri;

/**
 * class content video is used for keeping track of file changes
 * in db. Uses object fileUpload
 */
class module {

    public static $errors = null;
    public static $status = null;
    public static $parent_id;
    public static $fileId;
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
        template::setTitle(lang::translate($title));
    }
    
    public function addAction() {
        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }

        $options = self::getOptions();
        
        self::setHeadlineTitle('add');
        
        layout::setMenuFromClassPath($options['reference']);

        //$video = new self($options);
        $this->viewFileFormInsert();

        $options['admin'] = true;
        $rows = $this->getAllvideoInfo($options);
        echo $this->displayAllVideo($rows, $options);
//print_r($rows);
    }

    public function deleteAction() {

        $options = self::getOptions();
        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }

        self::setHeadlineTitle('delete');
        layout::setMenuFromClassPath($options['reference']);

        $this->viewFileFormDelete();
    }

    public function editAction() {

        if (!session::checkAccessControl('video_allow_edit')) {
            return;
        }

        self::setHeadlineTitle('edit');
        $video->viewFileFormUpdate();
    }

    /**
     * constructor sets init vars
     */
    function __construct($options = null) {
        self::setFileId();
        self::$options = $options;

        if (!isset($options['maxsize'])) {
            $maxsize = conf::getModuleIni('video_max_size');
            if ($maxsize) {
                self::$options['maxsize'] = $maxsize;
            }
        }
    }

    public static function setFileId() {
        self::$fileId = uri::fragment(2);
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

        $values = html::specialEncode($values);
        
        html::formStart('file_upload_form');
        if ($method == 'delete' && isset($id)) {
            $legend = lang::translate('Delete video');
            html::legend($legend);
            html::submit('submit', lang::translate('Delete'));
            html::formEnd();
            echo html::getStr();
            return;
        }

        $legend = '';
        if (isset($id)) {
            $values = self::getSingleFileInfo($id);
            html::init($values, 'submit');
            $legend = lang::translate('Edit video');
            $submit = lang::translate('Update video');
        } else {
            $legend = lang::translate('Add video');
            $submit = lang::translate('Add video');
        }

        html::legend($legend);
        html::label('abstract', lang::translate('Abstract'));
        html::textareaSmall('abstract');

        $bytes = conf::getModuleIni('video_max_size');
        html::fileWithLabel('file', $bytes);

        html::submit('submit', $submit);
        html::formEnd();
        echo html::getStr();
        return;
    }

    /**
     * method for inserting a module into the database
     * (access control is cheched in controller file)
     *
     * @return boolean true on success or false on failure
     */
    public function insertFile() {

        $values = db::prepareToPost();

        $db = new db();
        $res = $this->uploadVideo();
        if ($res) {

            $db->begin();

            $values['parent_id'] = self::$options['parent_id'];
            $values['reference'] = self::$options['reference'];
            $values['title'] = $_FILES['file']['name'];
            $values['mimetype'] = $_FILES['file']['type'];
            $values['full_path'] = self::$options['flv_file_path'];
            $values['web_path'] = self::$options['flv_web_path'];
            $values['full_path_mp4'] = self::$options['mp4_file_path'];
            $values['web_path_mp4'] = self::$options['mp4_web_path'];
            $res = $db->insert(self::$fileTable, $values);
            if ($res) {
                $db->commit();
            } else {
                $db->rollback();
            }
        }

        return $res;
    }
    
    public static  function includePlayer() {
        if (!conf::getModuleIni('video_player')) {
            conf::setModuleIni('video_player', 'videojs');
        }
        
        include_once conf::getModuleIni('video_player') . "/driver.php";
        
    }

    /**
     * Get videos connected to a entity: 
     * 
     * @param type $options
     * @return type
     */
    public static function subModuleInlineContent($options) {

        self::includePlayer();
        $str = video_player_include();
        $info = self::getAllVideoInfo($options);


        foreach ($info as $video) {
            $str.= video_player_get_html($video);
        }
        return $str;
    }

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

        // transform to flv
        $tmp_flv = $flv_file = null;
        if (isset($_FILES['file'])) {

            $flv_file = file::getFilename($_FILES['file']['name']) . ".flv";
            $tmp_flv = "/tmp/" . $flv_file;
            $res = $this->transformVideo($_FILES['file']['tmp_name'], $tmp_flv);

            if (!$res) {
                self::$errors[] = lang::translate('Could not transform to flv');
                return false;
            }

            $mp4_file = file::getFilename($_FILES['file']['name']) . ".mp4";
            $tmp_mp4 = "/tmp/" . $mp4_file;
            $res = $this->transformVideo($_FILES['file']['tmp_name'], $tmp_mp4);

            if (!$res) {
                self::$errors[] = lang::translate('Could not transform to flv');
                return false;
            }
        }

        self::$options = self::getOptions();
        $web_dir = "/video/" . self::$options['reference'] . "/" . self::$options['parent_id'];
        $web_dir = conf::getWebFilesPath($web_dir);
        $full_path = conf::pathHtdocs() . $web_dir;
        if (!file_exists($full_path)) {
            mkdir($full_path, 0777, true);
        }

        // copy flv
        $flv_web_path = $web_dir . "/" . $flv_file;
        $flv_full_path = $full_path . "/" . $flv_file;

        $copied = copy($tmp_flv, $flv_full_path);
        unlink($tmp_flv);

        // copy mp4
        $mp4_web_path = $web_dir . "/" . $mp4_file;
        $mp4_full_path = $full_path . "/" . $mp4_file;

        $copied = copy($tmp_mp4, $mp4_full_path);
        unlink($tmp_mp4);


        self::$options['flv_file_path'] = $flv_full_path;
        self::$options['flv_web_path'] = $flv_web_path;

        self::$options['mp4_file_path'] = $mp4_full_path;
        self::$options['mp4_web_path'] = $mp4_web_path;

        return $copied;
    }

    function transformVideo($full_filename, $full_filename_flv) {
        set_time_limit(0);

        //$command = "avconv -i \"$full_filename\" -c copy \"$full_filename_flv\";";
        $command = "avconv -i \"$full_filename\" -c:v libx264 -c:a copy   \"$full_filename_flv\";";
        //$ffmpeg_command = "ffmpeg -i \"$full_filename\" -ar 44100 -ab 96 -f flv \"$full_filename_flv\";";
        $output = array();
        exec($command, $output, $ret);
        if ($ret) {
            return false;
        }

        return true;
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

        $file = conf::pathHtdocs() . "" . $row['web_path'];
        if (file_exists($file)) {
            unlink($row['full_path']); //filename)
            unlink($row['full_path_mp4']);
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
        
        return html::createLink($url, lang::translate('Add video'), $extra); //$url;

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
    public static function displayAllVideo($rows, $options) {
        $str = '';

        foreach ($rows as $val) {
            $title = lang::translate('Download video');
            $title.= MENU_SUB_SEPARATOR_SEC;
            $title.= $val['title'];

            $link_options = array('title' => $val['abstract']);

            $path = conf::getWebFilesPath();
            $str.= html::createLink(
                            "$val[web_path_mp4]", $title, $link_options
            );

            // as a sub module the sub module can not know anything about the
            // id of individual video. That's why we will add id. 
            //print_r($options);
            $options['id'] = $val['id'];
            if (isset($options['admin'])) {

                // delete link
                $delete = "/video/delete/$val[id]?" . $options['query'];
                $str.= MENU_SUB_SEPARATOR;
                $str.= html::createLink($delete, lang::translate('Delete'));
            }
            $str.= "<br />\n";
        }
        return $str;
    }

    /**
     * method for getting all info connected to modules.
     *
     * @return array assoc rows of modules belonging to user
     */
    public static function getAllVideoInfo($options) {
        $db = new db();
        $search = array(
            'parent_id' => $options['parent_id'],
            'reference' => $options['reference']
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
        if (!$id)
            $id = self::$fileId;
        $db = new db();
        $search = array(
            'id' => $id
        );

        $fields = array('id', 'parent_id', 'full_path', 'web_path', 'title', 'abstract', 'published', 'created', 'reference');
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

        $bind = array();
        $values['abstract'] = html::specialDecode($_POST['abstract']);

        $db = new db();
        if (!empty($_FILES['file']['name'])) {

            $options = array();
            $options['filename'] = 'file';
            $options['maxsize'] = self::$options['maxsize'];

            $fp = blob::getFP('file', $options);
            if (!$fp) {
                self::$errors = blob::$errors;
                return false;
            }
            $values['file'] = $fp;
            $values['title'] = $_video['file']['name'];
            $values['mimetype'] = $_video['file']['type'];

            $bind = array('file' => PDO::PARAM_LOB);
        }
        $res = $db->update(self::$fileTable, $values, self::$fileId, $bind);
        return $res;
    }

    /**
     * method to be used in a insert controller
     */
    public function viewFileFormInsert() {

        $options = self::getOptions();
        if (isset($_POST['submit'])) {
            $this->validateInsert();
            if (!isset(self::$errors)) {
                $res = $this->insertFile();
                if ($res) {
                    session::setActionMessage(lang::translate('Video was added'));
                    $url = "/video/add/?$options[query]";
                    http::locationHeader($url);
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
    public function viewFileFormDelete() {
        $options = self::getOptions();
        if (isset($_POST['submit'])) {
            if (!isset(self::$errors)) {
                $res = $this->deleteFile(self::$fileId);
                if ($res) {
                    session::setActionMessage(lang::translate('Video was deleted'));
                    $url = "/video/add/?$options[query]";
                    http::locationHeader($url);
                    exit;
                }
            } else {
                html::errors(self::$errors);
            }
        }
        $this->viewFileForm('delete', self::$fileId);
    }
    
    public static function redirectVideoMain ($options) {
        $url = "/files/add/?$options[query]";
        http::locationHeader($url);   
    }

    /**
     * merhod to be used in an update controller 
     */
    public function viewFileFormUpdate() {

        if (isset($_POST['submit'])) {
            if (!isset(self::$errors)) {
                $res = $this->updateFile();
                if ($res) {
                    session::setActionMessage(lang::translate('Video was edited'));
                    $header = "Location: " . self::redirectVideoMain(self::$options);
                    header($header);
                    exit;
                } else {
                    html::errors(self::$errors);
                }
            } else {
                html::errors(self::$errors);
            }
        }
        $this->viewFileForm('update', self::$fileId);
    }

}
