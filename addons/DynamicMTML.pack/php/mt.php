<?php
/***
 * Loading exception classes
 */
$mt_root_dir = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
require_once($mt_root_dir.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'class.exception.php');
//require_once('lib/class.exception.php');

require_once($mt_root_dir.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'mt.php');

// more beatiful class name....
class MyMT extends MT{

    /***
     * Constructor for MT class.
     * Currently, constructor moved to private method because this class implemented Singleton Design Pattern.
     * You can get instance as following code.
     *
     * $mt = MT::get_instance();
     */
    function __construct($blog_id = null, $cfg_file = null) {
        global $mt_root_dir;
        $this->mt_dir = $mt_root_dir;
        $this->php_dir = $mt_root_dir . DIRECTORY_SEPARATOR . 'php';
        error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
        try {
            $this->id = md5(uniqid('MT',true));
            $this->init($blog_id, $cfg_file);
        } catch (Exception $e ) {
            throw new MTInitException( $e, $this->debugging );
        }
    }

    function init($blog_id = null, $cfg_file = null) {
        if (isset($blog_id)) {
            $this->blog_id = $blog_id;
        }

        if (!file_exists($cfg_file)) {
            // $mtdir = dirname(dirname(__FILE__));
            $mtdir = $this->mt_dir;
            $cfg_file = $mtdir . DIRECTORY_SEPARATOR . "mt-config.cgi";
        }

        $this->configure($cfg_file);
        $this->init_addons();
        $this->configure_from_db();

        $lang = substr(strtolower($this->config('DefaultLanguage')), 0, 2);
        if (!@include_once("l10n_$lang.php"))
            include_once("l10n_en.php");

        if (extension_loaded('mbstring')) {
            $charset = $this->config('PublishCharset');
            mb_internal_encoding($charset);
            mb_http_output($charset);
        }
    }

    function init_addons() {
        // $mtdir = dirname(dirname(__FILE__));
        $mtdir = $this->mt_dir;
        $path = $mtdir . DIRECTORY_SEPARATOR . "addons";
        if (is_dir($path)) {
            $ctx =& $this->context();
            if ($dh = opendir($path)) {
                while (($file = readdir($dh)) !== false) {
                    if ($file == "." || $file == "..") {
                        continue;
                    }
                    $plugin_dir = $path . DIRECTORY_SEPARATOR . $file
                        . DIRECTORY_SEPARATOR . 'php';
                    if (is_dir($plugin_dir))
                        $ctx->add_plugin_dir($plugin_dir);
                }
                closedir($dh);
            }
        }
    }

    /***
     * Loads configuration data from mt.cfg and mt-db-pass.cgi files.
     * Stores content in the 'config' member variable.
     */
    function configure($file = null) {
        if (isset($this->config)) return $config;

        $this->cfg_file = $file;

        $cfg = array();
        $type_array = array('pluginpath', 'alttemplate', 'outboundtrackbackdomains', 'memcachedservers');
        $type_hash  = array('commenterregistration');
        if ($fp = file($file)) {
            foreach ($fp as $line) {
                // search through the file
                if (!preg_match('/^\s*\#/i',$line)) {
                    // ignore lines starting with the hash symbol
                    if (preg_match('/^\s*(\S+)\s+(.*)$/', $line, $regs)) {
                        $key = strtolower(trim($regs[1]));
                        $value = trim($regs[2]);
                        if (in_array($key, $type_array)) {
                            $cfg[$key][] = $value;
                        }
                        elseif (in_array($key, $type_hash)) {
                            $hash = preg_split('/\=/', $value, 2);
                            $cfg[$key][strtolower(trim($hash[0]))] = trim($hash[1]);
                        } else {
                            $cfg[$key] = $value;
                        }
                    }
                }
            }
        } else {
            // die("Unable to open configuration file $file");
        }
        // setup directory locations
        // location of mt.php
        $cfg['phpdir'] = realpath(dirname(__FILE__));
        $cfg['phpdir'] = realpath($this->php_dir);
        // path to MT directory
        $cfg['mtdir'] = realpath(dirname($file));
        // path to handlers
        $cfg['phplibdir'] = $cfg['phpdir'] . DIRECTORY_SEPARATOR . 'lib';

        $cfg['dbhost'] or $cfg['dbhost'] = 'localhost'; // default to localhost
        $driver = $cfg['objectdriver'];
        $driver = preg_replace('/^DB[ID]::/', '', $driver);
        $driver or $driver = 'mysql';
        $cfg['dbdriver'] = strtolower($driver);
        // No database, continue,
        if (strlen($cfg['database'])) {
            if (strlen($cfg['dbuser'])<1) {
                if (($cfg['dbdriver'] != 'sqlite') && ($cfg['dbdriver'] != 'mssqlserver') && ($cfg['dbdriver'] != 'umssqlserver')) {
                    die("Unable to read database or username");
                }
            }
        }
        if ( !empty( $cfg['debugmode'] ) && intval($cfg['debugmode']) > 0 ) {
            $this->debugging = true;
        }

        $this->config =& $cfg;
        $this->config_defaults();

        // read in the database password
        if (!isset($cfg['dbpassword'])) {
            $db_pass_file = $cfg['mtdir'] . DIRECTORY_SEPARATOR . 'mt-db-pass.cgi';
            if (file_exists($db_pass_file)) {
                $password = implode('', file($db_pass_file));
                $password = trim($password, "\n\r\0");
                $cfg['dbpassword'] = $password;
            }
        }

        // set up include path
        // add MT-PHP 'plugins' and 'lib' directories to the front
        // of the existing PHP include path:
        if (strtoupper(substr(PHP_OS, 0,3) == 'WIN')) {
            $path_sep = ';';
        } else {
            $path_sep = ':';
        }
        ini_set('include_path',
            $cfg['phpdir'] . DIRECTORY_SEPARATOR . "lib" . $path_sep .
            $cfg['phpdir'] . DIRECTORY_SEPARATOR . "extlib" . $path_sep .
            $cfg['phpdir'] . DIRECTORY_SEPARATOR . "extlib" . DIRECTORY_SEPARATOR . "smarty" . DIRECTORY_SEPARATOR . "libs" . $path_sep .
            $cfg['phpdir'] . DIRECTORY_SEPARATOR . "extlib" . DIRECTORY_SEPARATOR . "adodb5" . $path_sep .
            $cfg['phpdir'] . DIRECTORY_SEPARATOR . "extlib" . DIRECTORY_SEPARATOR . "FirePHPCore" . $path_sep .
            ini_get('include_path')
        );
    }

    /***
     * Mainline handler function.
     */
    function view($blog_id = null) {

        set_error_handler(array(&$this, 'error_handler'));

        require_once("MTUtil.php");

        $blog_id or $blog_id = $this->blog_id;

       try {
           $ctx =& $this->context();
           $this->init_plugins();
           $ctx->caching = $this->caching;

           // Some defaults...
            $mtdb =& $this->db();
            $ctx->mt->db =& $mtdb;
       } catch (Exception $e ) {
            if ( $this->debugging ) {
                $msg = "<b>Error:</b> ". $e->getMessage() ."<br>\n" .
                       "<pre>".$e->getTraceAsString()."</pre>";

                return trigger_error( $msg, E_USER_ERROR);
            }
            header( "503 Service Unavailable" );
            return false;
        }

        // User-specified request through request variable
        $path = $this->request;

        // Apache request
        if (!$path && $_SERVER['REQUEST_URI']) {
            $path = $_SERVER['REQUEST_URI'];
            // strip off any query string...
            $path = preg_replace('/\?.*/', '', $path);
            // strip any duplicated slashes...
            $path = preg_replace('!/+!', '/', $path);
        }

        // IIS request by error document...
        if (preg_match('/IIS/', $_SERVER['SERVER_SOFTWARE'])) {
            // assume 404 handler
            if (preg_match('/^\d+;(.*)$/', $_SERVER['QUERY_STRING'], $matches)) {
                $path = $matches[1];
                $path = preg_replace('!^http://[^/]+!', '', $path);
                if (preg_match('/\?(.+)?/', $path, $matches)) {
                    $_SERVER['QUERY_STRING'] = $matches[1];
                    $path = preg_replace('/\?.*$/', '', $path);
                }
            }
        }

        // now set the path so it may be queried
        $path = preg_replace('/\\\\/', '\\\\\\\\', $path );
        $this->request = $path;

        $pathinfo = pathinfo($path);
        $ctx->stash('_basename', $pathinfo['filename']);

        // When we are invoked as an ErrorDocument, the parameters are
        // in the environment variables REDIRECT_*
        if (isset($_SERVER['REDIRECT_QUERY_STRING'])) {
            // todo: populate $_GET and QUERY_STRING with REDIRECT_QUERY_STRING
            $_SERVER['QUERY_STRING'] = getenv('REDIRECT_QUERY_STRING');
        }

        if (preg_match('/\.(\w+)$/', $path, $matches)) {
            $req_ext = strtolower($matches[1]);
        }

        $this->blog_id = $blog_id;

        $data = $this->resolve_url($path);
        if (!$data) {
            // 404!
            $this->http_error = 404;
            header("HTTP/1.1 404 Not found");
            return $ctx->error($this->translate("Page not found - [_1]", $path), E_USER_ERROR);
        }

        $fi_path = $data->fileinfo_url;
        $fid = $data->fileinfo_id;
        $at = $data->fileinfo_archive_type;
        $ts = $data->fileinfo_startdate;
        $tpl_id = $data->fileinfo_template_id;
        $cat = $data->fileinfo_category_id;
        $auth = $data->fileinfo_author_id;
        $entry_id = $data->fileinfo_entry_id;
        $blog_id = $data->fileinfo_blog_id;
        $blog = $data->blog();
        if ($at == 'index') {
            $at = null;
            $ctx->stash('index_archive', true);
        } else {
            $ctx->stash('index_archive', false);
        }
        $tmpl = $data->template();
        $ctx->stash('template', $tmpl);

        $tts = $tmpl->template_modified_on;
        if ($tts) {
            $tts = offset_time(datetime_to_timestamp($tts), $blog);
        }
        $ctx->stash('template_timestamp', $tts);
        $ctx->stash('template_created_on', $tmpl->template_created_on);

        $page_layout = $blog->blog_page_layout;
        $columns = get_page_column($page_layout);
        $vars =& $ctx->__stash['vars'];
        $vars['page_columns'] = $columns;
        $vars['page_layout'] = $page_layout;

        if (isset($tmpl->template_identifier))
            $vars[$tmpl->template_identifier] = 1;

        $this->configure_paths($blog->site_path());

        // start populating our stash
        $ctx->stash('blog_id', $blog_id);
        $ctx->stash('local_blog_id', $blog_id);
        $ctx->stash('blog', $blog);
        $ctx->stash('build_template_id', $tpl_id);

        // conditional get support...
        if ($this->caching) {
            $this->cache_modified_check = true;
        }
        if ($this->conditional) {
            $last_ts = $blog->blog_children_modified_on;
            $last_modified = $ctx->_hdlr_date(array('ts' => $last_ts, 'format' => '%a, %d %b %Y %H:%M:%S GMT', 'language' => 'en', 'utc' => 1), $ctx);
            $this->doConditionalGet($last_modified);
        }

        $cache_id = $blog_id.';'.$fi_path;
        if (!$ctx->is_cached('mt:'.$tpl_id, $cache_id)) {
            if (isset($at) && ($at != 'Category')) {
                require_once("archive_lib.php");
                try {
                    $archiver = ArchiverFactory::get_archiver($at);
                } catch (Execption $e) {
                    // 404
                    $this->http_errr = 404;
                    header("HTTP/1.1 404 Not Found");
                    return $ctx->error($this->translate("Page not found - [_1]", $at), E_USER_ERROR);
                }
                $archiver->template_params($ctx);
            }

            if ($cat) {
                // Folder Archive Support
                $archive_category = $mtdb->fetch_category($cat);
                if (! $archive_category) {
                    $archive_category = $mtdb->fetch_folder($cat);
                }
                $ctx->stash('category', $archive_category);
                $ctx->stash('archive_category', $archive_category);
            }
            if ($auth) {
                $archive_author = $mtdb->fetch_author($auth);
                $ctx->stash('author', $archive_author);
                $ctx->stash('archive_author', $archive_author);
            }
            if (isset($at)) {
                if (($at != 'Category') && isset($ts)) {
                    list($ts_start, $ts_end) = $archiver->get_range($ts);
                    $ctx->stash('current_timestamp', $ts_start);
                    $ctx->stash('current_timestamp_end', $ts_end);
                }
                $ctx->stash('current_archive_type', $at);
            }
    
            if (isset($entry_id) && ($entry_id) && ($at == 'Individual' || $at == 'Page')) {
                if ($at == 'Individual') {
                    $entry =& $mtdb->fetch_entry($entry_id);
                } elseif($at == 'Page') {
                    $entry =& $mtdb->fetch_page($entry_id);
                }
                $ctx->stash('entry', $entry);
                $ctx->stash('current_timestamp', $entry->entry_authored_on);
            }

            if ($at == 'Category') {
                $vars =& $ctx->__stash['vars'];
                $vars['archive_class']            = "category-archive";
                $vars['category_archive']         = 1;
                $vars['archive_template']         = 1;
                $vars['archive_listing']          = 1;
                $vars['module_category_archives'] = 1;
            }
        }

        $output = $ctx->fetch('mt:'.$tpl_id, $cache_id);

        $this->http_error = 200;
        header("HTTP/1.1 200 OK");
        // content-type header-- need to supplement with charset
        $content_type = $ctx->stash('content_type');

        if (!isset($content_type)) {
            $content_type = $this->mime_types['__default__'];
            if ($req_ext && (isset($this->mime_types[$req_ext]))) {
                $content_type = $this->mime_types[$req_ext];
            }
        }
        $charset = $this->config('PublishCharset');
        if (isset($charset)) {
            if (!preg_match('/charset=/', $content_type))
                $content_type .= '; charset=' . $charset;
        }
        header("Content-Type: $content_type");

        // finally, issue output
        $output = preg_replace('/^\s*/', '', $output);
        echo $output;

        // if warnings found, show it.
        if (!empty($this->warning)) {
            $this->_dump($this->warning);
        }

#        if ($this->debugging) {
#            $this->log("Queries: ".$mtdb->num_queries);
#            $this->log("Queries executed:");
#            $queries = $mtdb->savedqueries;
#            foreach ($queries as $q) {
#                $this->log($q);
#            }
#            $this->log_dump();
#        }
        restore_error_handler();
    }

    function doConditionalGet($last_modified) {
        // Thanks to Simon Willison...
        //   http://simon.incutio.com/archive/2003/04/23/conditionalGet
        // A PHP implementation of conditional get, see 
        //   http://fishbowl.pastiche.org/archives/001132.html
        $etag = '"'.md5($last_modified).'"';
        // Send the headers
        header("Last-Modified: $last_modified");
        header("ETag: $etag");
        // See if the client has provided the required headers
        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ?
            stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE']) :
            false;
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ?
            stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) : 
            false;
        if (!$if_modified_since && !$if_none_match) {
            return;
        }
        // At least one of the headers is there - check them
        if ($if_none_match && $if_none_match != $etag) {
            return; // etag is there but doesn't match
        }
        if ($if_modified_since && $if_modified_since != $last_modified) {
            return; // if-modified-since is there but doesn't match
        }
        // Nothing has changed since their last request - serve a 304 and exit
        header('HTTP/1.1 304 Not Modified');
        exit;
    }

}
?>
