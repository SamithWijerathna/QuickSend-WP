<?php
/**
 * Plugin Name: FTP/SFTP File Transfer
 * Description: A plugin to list, search and select multiple files from your entire WordPress installation and transfer them via FTP or SFTP to a remote server with real-time progress and speed.
 * Version: 1.9
 * Author: Samith
 * Author URI: https://www.samithwijerathna.com
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

spl_autoload_register(function($class){
    $pre = 'phpseclib3\\';
    $dir = __DIR__ . '/libs/phpseclib/';
    if (strpos($class, $pre) !== 0) return;
    $rel = str_replace('\\','/',substr($class,strlen($pre))) . '.php';
    $file = $dir . $rel;
    if (file_exists($file)) require $file;
});

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class FTP_SFTP_File_Transfer_Plugin {
    private $base_dir;
    private $chunk_size = 67108864; // Reduce to 1MB for better stability
    private $max_retries = 5;
    private $retry_delay = 5;

    public function __construct() {
        $this->base_dir = untrailingslashit(ABSPATH);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_transfer_file_chunk', [$this, 'ajax_transfer_file_chunk']);
        add_action('wp_ajax_save_ftp_conn', [$this, 'ajax_save_ftp_conn']);
        add_action('wp_ajax_load_ftp_conn', [$this, 'ajax_load_ftp_conn']);
        add_action('wp_ajax_reset_ftp_conn', [$this, 'ajax_reset_ftp_conn']);
        add_action('wp_ajax_test_ftp_conn', [$this, 'ajax_test_ftp_conn']);
    }

    private function get_files($dir) {
        $files = [];
        $exclude = ['.git', 'node_modules', '.idea', '.DS_Store'];
        $max_files = 50000;
        $count = 0;
        
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($it as $f) {
                if ($count >= $max_files) break;
                if ($f->isFile() && !$f->isLink() && is_readable($f->getPathname())) {
                    $path = $f->getPathname();
                    $skip = false;
                    
                    foreach ($exclude as $ex) {
                        if (strpos($path, DIRECTORY_SEPARATOR . $ex . DIRECTORY_SEPARATOR) !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    
                    if (!$skip) {
                        $files[] = str_replace(ABSPATH, '', $path);
                        $count++;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('File enumeration error: ' . $e->getMessage());
        }
        
        return $files;
    }

    public function add_admin_menu() {
        add_menu_page('File Transfer', 'File Transfer', 'manage_options', 'file-transfer', [$this, 'admin_page'], 'dashicons-networking', 60);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die();
        $files = $this->get_files($this->base_dir);
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('transfer_nonce');
        $conn_nonce = wp_create_nonce('ftp_conn_nonce');
        ?>
        <div class="wrap"><h1>File Transfer (FTP/SFTP) - Chunked Upload with Real-Time Progress</h1>
        <style>
            #log-area {background:#f9f9f9; border:1px solid #ccc; height:150px; overflow:auto; padding:5px; font-family: monospace;}
            #progress-bar {width:100%; background:#eee; height:20px; border:1px solid #ccc; border-radius:3px; overflow:hidden;}
            #progress-fill {width:0; height:100%; background:#007cba; transition: width 0.3s;}
            .file-list table {table-layout: fixed; width: 100%;}
            .file-list td.path {word-wrap: break-word;}
        </style>

        <input id="file-search" placeholder="Search files..." style="width:300px; margin-bottom:10px;">
        <div class="file-list" style="max-height:300px; overflow:auto; border:1px solid #ddd; margin-bottom:15px;">
            <table class="widefat fixed" id="file-table">
                <thead><tr><th style="width:30px;"><input type="checkbox" id="select_all"></th><th>Path</th></tr></thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td><input type="checkbox" name="files[]" value="<?php echo esc_attr($f); ?>"></td>
                        <td class="path"><?php echo esc_html($f); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <table class="form-table">
            <tr><th>Protocol</th><td><select id="protocol"><option value="ftp">FTP</option><option value="sftp">SFTP</option></select></td></tr>
            <tr><th>Host</th><td><input type="text" id="host" placeholder="example.com"></td></tr>
            <tr><th>Port</th><td><input type="number" id="port" placeholder="21 or 22"></td></tr>
            <tr><th>User</th><td><input type="text" id="user"></td></tr>
            <tr><th>Pass/Key</th><td><input type="text" id="pass" placeholder="password or private key path"></td></tr>
            <tr><th>Remote Dir</th><td><input type="text" id="remote_dir" placeholder="/path/to/dir"></td></tr>
        </table>

        <button id="start" class="button button-primary">Start Transfer</button>
        <button id="save-ftp" class="button">Save</button>
        <button id="reset-ftp" class="button">Reset</button>

        <h2>Connection Log</h2><div id="log-area"></div>
        <h2>Progress</h2>
        <div id="progress-bar"><div id="progress-fill"></div></div>
        <div id="speed-info" style="margin-top:5px;font-family: monospace;"></div>

        <script>
        (function($){
            $('#select_all').on('change', function(){
                $('input[name="files[]"]').prop('checked', this.checked);
            });

            $('#file-search').on('keyup', function(){
                var term = $(this).val().toLowerCase();
                $('#file-table tbody tr').each(function(){
                    var t = $(this).find('.path').text().toLowerCase();
                    $(this).toggle(term === '' || t.indexOf(term) !== -1);
                });
            });

            function testConnection(protocol, host, port, user, pass, cb) {
                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'test_ftp_conn',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    protocol: protocol,
                    host: host,
                    port: port,
                    user: user,
                    pass: pass
                }, function(res) {
                    cb(res);
                }, 'json').fail(function(jqXHR) {
                    cb({success: false, data: jqXHR.responseJSON?.data || 'Connection error'});
                });
            }

            $('#start').on('click', function(){
                var sel = $('input[name="files[]"]:checked'),
                    files = sel.map(function(){ return this.value; }).get();
                if(!files.length) return alert('Select files');

                var protocol = $('#protocol').val(),
                    host = $('#host').val().trim(),
                    port = parseInt($('#port').val()),
                    user = $('#user').val().trim(),
                    pass = $('#pass').val().trim(),
                    remote_dir = $('#remote_dir').val().trim();

                if (!host || isNaN(port) || !user || !remote_dir) {
                    return alert('Please fill all required connection fields.');
                }

                $('#log-area').empty().append('<div class="notice notice-info">Testing connection...</div>');
                $('#progress-fill').css('width', '0');
                $('#speed-info').text('');

                testConnection(protocol, host, port, user, pass, function(res) {
                    if(res.success) {
                        $('#log-area').append('<div style="color:green">Connection successful. Starting transfer...</div>');
                        var totalFiles = files.length,
                            currentFileIndex = 0;

                        function log(text, isError) {
                            var color = isError ? '#ff0000' : '#000000';
                            $('#log-area').append('<div style="color:'+color+'">'+text+'</div>').scrollTop($('#log-area')[0].scrollHeight);
                        }

                        function uploadFileChunked(file) {
                            var chunkSize = <?php echo $this->chunk_size; ?>;
                            var offset = 0;
                            var startTime = Date.now();
                            var retries = 0;
                            var maxRetries = <?php echo $this->max_retries; ?>;
                            var retryDelay = <?php echo $this->retry_delay; ?>;

                            log('Starting upload: ' + file);

                            function uploadNextChunk() {
                                $.ajax({
                                    url: ajaxurl,
                                    method: 'POST',
                                    dataType: 'json',
                                    timeout: 60000,
                                    data: {
                                        action: 'transfer_file_chunk',
                                        nonce: '<?php echo esc_js($nonce); ?>',
                                        protocol: protocol,
                                        host: host,
                                        port: port,
                                        user: user,
                                        pass: pass,
                                        remote_dir: remote_dir,
                                        file: file,
                                        offset: offset,
                                        chunk_size: chunkSize
                                    },
                                    success: function(res) {
                                        if (res.success) {
                                            offset = res.data.new_offset;
                                            var percent = Math.min(100, Math.round((offset / res.data.filesize) * 100));
                                            var now = Date.now();
                                            var timeElapsed = (now - startTime) / 1000;
                                            var speed = (offset / 1024 / timeElapsed).toFixed(2);

                                            $('#progress-fill').css('width', percent + '%');
                                            $('#speed-info').text('Uploading ' + file + ': ' + percent + '% at ' + speed + ' KB/s');

                                            if (offset < res.data.filesize) {
                                                uploadNextChunk();
                                            } else {
                                                log(file + ' uploaded successfully.');
                                                currentFileIndex++;
                                                if (currentFileIndex < totalFiles) {
                                                    uploadFileChunked(files[currentFileIndex]);
                                                } else {
                                                    log('All files transferred successfully!');
                                                    $('#speed-info').text('');
                                                }
                                            }
                                            retries = 0;
                                        } else {
                                            log('Error uploading ' + file + ': ' + (res.data?.message || res.data), true);
                                            if (retries < maxRetries) {
                                                retries++;
                                                setTimeout(uploadNextChunk, retryDelay * 1000);
                                            }
                                        }
                                    },
                                    error: function(jqXHR, textStatus, errorThrown) {
                                        var msg = "HTTP Error: ";
                                        if (jqXHR.status === 0) msg += "Connection Failed";
                                        else if (jqXHR.status === 500) msg += "Server Error: " + (jqXHR.responseJSON?.message || '');
                                        else msg += jqXHR.status + " " + errorThrown;

                                        log(msg, true);
                                        if (retries < maxRetries) {
                                            retries++;
                                            setTimeout(uploadNextChunk, retryDelay * 1000);
                                        }
                                    }
                                });
                            }
                            uploadNextChunk();
                        }

                        uploadFileChunked(files[currentFileIndex]);
                    } else {
                        $('#log-area').append('<div style="color:red">Connection failed: ' + (res.data || 'Unknown error') + '</div>');
                    }
                });
            });

            $(function() {
                $.post(ajaxurl, {
                    action: 'load_ftp_conn',
                    nonce: '<?php echo esc_js($conn_nonce); ?>'
                }, function(res) {
                    if(res.success && res.data) {
                        var data = res.data;
                        $('#protocol').val(data.protocol || 'sftp');
                        $('#host').val(data.host || '');
                        $('#port').val(data.port || '');
                        $('#user').val(data.user || '');
                        $('#pass').val(data.pass || '');
                        $('#remote_dir').val(data.remote_dir || '');
                    }
                }, 'json');
            });

            $('#save-ftp').on('click', function() {
                var data = {
                    protocol: $('#protocol').val(),
                    host: $('#host').val(),
                    port: $('#port').val(),
                    user: $('#user').val(),
                    pass: $('#pass').val(),
                    remote_dir: $('#remote_dir').val()
                };
                $.post(ajaxurl, {
                    action: 'save_ftp_conn',
                    nonce: '<?php echo esc_js($conn_nonce); ?>',
                    data: data
                }, function(res) {
                    if(res.success) {
                        alert('Connection data saved!');
                    } else {
                        alert('Failed to save: ' + (res.data || 'Unknown error'));
                    }
                }, 'json');
            });

            $('#reset-ftp').on('click', function() {
                $.post(ajaxurl, {
                    action: 'reset_ftp_conn',
                    nonce: '<?php echo esc_js($conn_nonce); ?>'
                }, function(res) {
                    $('#protocol').val('sftp');
                    $('#host, #port, #user, #pass, #remote_dir').val('');
                    alert('Connection data reset.');
                }, 'json');
            });
        })(jQuery);
        </script>
        </div>
        <?php
    }

    public function ajax_transfer_file_chunk() {
        error_log('ajax_transfer_file_chunk called');
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        @ini_set('display_errors', 0);

        check_ajax_referer('transfer_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission.');

        try {
            $required = ['protocol', 'host', 'port', 'user', 'pass', 'remote_dir', 'file'];
            foreach ($required as $key) {
                if (empty($_POST[$key])) throw new Exception("Missing parameter: $key");
            }

            $p = $_POST;
            $protocol = sanitize_text_field($p['protocol']);
            $host = sanitize_text_field($p['host']);
            $port = intval($p['port']);
            $user = sanitize_text_field($p['user']);
            $pass = sanitize_text_field($p['pass']);
            $remote_dir = sanitize_text_field($p['remote_dir']);
            $file = ltrim(sanitize_text_field($p['file']), '/');
            $offset = intval($p['offset'] ?? 0);
            $chunk_size = intval($p['chunk_size'] ?? $this->chunk_size);

            $local_path = ABSPATH . $file;
            if (!file_exists($local_path)) throw new Exception("Local file missing: $file");

            $filesize = filesize($local_path);
            $remote_path = rtrim($remote_dir, '/') . '/' . $file;
            $remote_dir_path = dirname($remote_path);

            if ($protocol === 'sftp') {
                $sftp = new SFTP($host, $port, 30); // Reduced timeout
                $sftp->setTimeout(15);
                if (method_exists($sftp, 'setKeepAlive')) $sftp->setKeepAlive(10);

                // Improved login with connection test
                $logged_in = false;
                $auth_error = '';
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if (file_exists($pass)) {
                            $key = PublicKeyLoader::load(file_get_contents($pass));
                            $logged_in = $sftp->login($user, $key);
                        } else {
                            $logged_in = $sftp->login($user, $pass);
                        }
                        if ($logged_in) {
                            if (!$sftp->ping()) {
                                throw new Exception('Connection unstable');
                            }
                            break;
                        }
                    } catch (Exception $e) {
                        $auth_error = $e->getMessage();
                        error_log("SFTP Connection Error (Attempt ".($i+1)."): ".$auth_error);
                        sleep($this->retry_delay);
                    }
                }
                if (!$logged_in) throw new Exception("Auth failed: ".$auth_error);

                if (!$sftp->file_exists($remote_dir_path)) {
                    $sftp->mkdir($remote_dir_path, 0755, true);
                }

                $remote_size = $sftp->file_exists($remote_path . '.part') ? $sftp->filesize($remote_path . '.part') : 0;
                if ($remote_size > $offset) {
                    $offset = $remote_size;
                    error_log("Offset corrected to $remote_size");
                }

                // Create temp file for chunk
                $temp_file = tempnam(sys_get_temp_dir(), 'sftp_chunk');
                
                try {
                    // Read chunk to temp file
                    $fp = fopen($local_path, 'rb');
                    fseek($fp, $offset);
                    $chunk = fread($fp, min($chunk_size, $filesize - $offset));
                    fclose($fp);
                    
                    if (!file_put_contents($temp_file, $chunk)) {
                        throw new Exception("Failed to write chunk to temp file");
                    }

                    // Upload using temp file
                    $tmp_remote = $remote_path . '.part';
                    $mode = $offset === 0 ? SFTP::SOURCE_LOCAL_FILE : SFTP::SOURCE_LOCAL_FILE | SFTP::RESUME;
                    
                    if (!$sftp->put($tmp_remote, $temp_file, $mode)) {
                        throw new Exception("Chunk upload failed: " . implode(', ', $sftp->getSFTPErrors()));
                    }

                    // Only rename on final chunk
                    if (($offset + strlen($chunk)) >= $filesize) {
                        // Delete target file if it exists
                        if ($sftp->file_exists($remote_path)) {
                            $sftp->delete($remote_path);
                        }

                        // Retry rename operation
                        $renamed = false;
                        for ($i = 0; $i < $this->max_retries; $i++) {
                            try {
                                // Ensure connection and file exists
                                if (!$sftp->isConnected()) {
                                    $sftp->connect($host, $port);
                                }
                                if (!$sftp->file_exists($tmp_remote)) {
                                    throw new Exception("Part file missing before rename");
                                }
                                
                                if ($sftp->rename($tmp_remote, $remote_path)) {
                                    $renamed = true;
                                    break;
                                }
                                error_log("Rename attempt $i failed: " . implode(', ', $sftp->getSFTPErrors()));
                                sleep($this->retry_delay);
                            } catch (Exception $e) {
                                error_log("Rename attempt $i exception: " . $e->getMessage());
                                sleep($this->retry_delay);
                            }
                        }

                        if (!$renamed) {
                            throw new Exception("Final rename failed after {$this->max_retries} attempts. Errors: " . 
                                implode(', ', $sftp->getSFTPErrors()));
                        }
                    }

                    wp_send_json_success([
                        'filesize' => $filesize,
                        'chunk_size' => strlen($chunk),
                        'new_offset' => $offset + strlen($chunk)
                    ]);

                } finally {
                    // Clean up temp file
                    if (file_exists($temp_file)) {
                        @unlink($temp_file);
                    }
                }
            }
        } catch (Exception $e) {
            $error = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'server' => "PHP " . phpversion() . " | " . $_SERVER['SERVER_SOFTWARE'],
                'trace' => $e->getTraceAsString()
            ];
            error_log("SFTP ERROR: " . print_r($error, true));
            wp_send_json_error($error);
        }
    }

    public function ajax_test_ftp_conn() {
        check_ajax_referer('transfer_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission.');

        try {
            $required = ['protocol', 'host', 'port', 'user', 'pass'];
            foreach ($required as $key) {
                if (empty($_POST[$key])) throw new Exception("Missing parameter: $key");
            }
            $protocol = sanitize_text_field($_POST['protocol']);
            $host = sanitize_text_field($_POST['host']);
            $port = intval($_POST['port']);
            $user = sanitize_text_field($_POST['user']);
            $pass = sanitize_text_field($_POST['pass']);

            if ($protocol === 'sftp') {
                $sftp = new SFTP($host, $port, 10);
                $sftp->setTimeout(10);
                $logged_in = false;
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if (file_exists($pass)) {
                            $key = PublicKeyLoader::load(file_get_contents($pass), $passphrase ?? '');
                            $logged_in = $sftp->login($user, $key);
                        } else {
                            $logged_in = $sftp->login($user, $pass);
                        }
                        if ($logged_in) break;
                        sleep($this->retry_delay);
                    } catch (Exception $e) {
                        error_log("SFTP Test Login Attempt $i failed: " . $e->getMessage());
                    }
                }
                if (!$logged_in) throw new Exception('SFTP login failed: ' . $sftp->getLastError());
                wp_send_json_success('Connection successful');
            } else {
                throw new Exception('FTP protocol not supported in this version');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_save_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission.');
        
        $data = isset($_POST['data']) ? array_map('sanitize_text_field', $_POST['data']) : [];
        if (!is_array($data)) wp_send_json_error('Invalid data format');
        
        update_user_meta(get_current_user_id(), '_ftp_sftp_conn', $data);
        wp_send_json_success();
    }

    public function ajax_load_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission.');
        
        $data = get_user_meta(get_current_user_id(), '_ftp_sftp_conn', true);
        wp_send_json_success($data ?: []);
    }

    public function ajax_reset_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission.');
        
        delete_user_meta(get_current_user_id(), '_ftp_sftp_conn');
        wp_send_json_success();
    }
}

new FTP_SFTP_File_Transfer_Plugin();