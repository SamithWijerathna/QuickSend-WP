<?php
/**
 * Plugin Name: FTP/SFTP File Transfer
 * Description: A plugin to list, search and select multiple files from your entire WordPress installation and transfer them via FTP or SFTP to a remote server with real-time progress and speed.
 * Version: 1.9.1
 * Author: Samith (Fixed by Claude)
 * Author URI: https://www.samithwijerathna.com
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

// Autoload phpseclib classes
spl_autoload_register(function($class){
    $pre = 'phpseclib3\\';
    $dir = __DIR__ . '/libs/phpseclib/';
    if (strpos($class, $pre) !== 0) return;
    $rel = str_replace('\\','/',substr($class,strlen($pre))) . '.php';
    $file = $dir . $rel;
    if (file_exists($file)) require $file;
});

// Import required phpseclib classes
use phpseclib3\Net\SFTP;
use phpseclib3\Net\FTP;
use phpseclib3\Crypt\PublicKeyLoader;

class FTP_SFTP_File_Transfer_Plugin {
    private $base_dir;
    private $chunk_size = 8388608; // 8MB chunks for better stability
    private $max_retries = 5;
    private $retry_delay = 3;
    private $log_file;

    public function __construct() {
        $this->base_dir = untrailingslashit(ABSPATH);
        $this->log_file = WP_CONTENT_DIR . '/ftp-sftp-transfer.log';
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_transfer_file_chunk', [$this, 'ajax_transfer_file_chunk']);
        add_action('wp_ajax_save_ftp_conn', [$this, 'ajax_save_ftp_conn']);
        add_action('wp_ajax_load_ftp_conn', [$this, 'ajax_load_ftp_conn']);
        add_action('wp_ajax_reset_ftp_conn', [$this, 'ajax_reset_ftp_conn']);
        add_action('wp_ajax_test_ftp_conn', [$this, 'ajax_test_ftp_conn']);
    }

    /**
     * Write to log file for debugging
     */
    private function log($message) {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        $timestamp = date('[Y-m-d H:i:s] ');
        file_put_contents($this->log_file, $timestamp . $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Get list of files for transfer
     */
    private function get_files($dir) {
        $files = [];
        $exclude = ['.git', 'node_modules', '.idea', '.DS_Store', 'cache', 'tmp'];
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
            $this->log('File enumeration error: ' . $e->getMessage());
        }
        
        return $files;
    }

    /**
     * Create remote directory recursively
     */
    private function create_remote_dirs($connection, $path, $protocol = 'sftp') {
        $path = str_replace('\\', '/', $path);
        $dirs = explode('/', $path);
        $current = '';
        
        foreach ($dirs as $dir) {
            if (!$dir) continue;
            $current .= '/' . $dir;
            
            if ($protocol === 'sftp') {
                if (!$connection->file_exists($current)) {
                    if (!$connection->mkdir($current, 0755)) {
                        $this->log("Failed to create directory: $current");
                    }
                }
            } else if ($protocol === 'ftp') {
                // For FTP, we need to check if exists and create if not
                if (!@$connection->chdir($current)) {
                    $connection->mkdir($current);
                    $connection->chdir($current);
                }
                // Always go back to root after checking
                $connection->chdir('/');
            }
        }
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        $page = add_menu_page('File Transfer', 'File Transfer', 'manage_options', 'file-transfer', [$this, 'admin_page'], 'dashicons-networking', 60);
    }

    /**
     * Enqueue scripts for the admin page
     */
    public function enqueue_scripts($hook) {
        if ('toplevel_page_file-transfer' != $hook) {
            return;
        }
        
        wp_enqueue_style('file-transfer-css', admin_url('admin-ajax.php?action=file_transfer_css'), [], '1.0.0');
        
        // Inline CSS as a fallback
        $custom_css = "
            #log-area {background:#f9f9f9; border:1px solid #ccc; height:200px; overflow:auto; padding:5px; font-family: monospace;}
            #progress-bar {width:100%; background:#eee; height:20px; border:1px solid #ccc; border-radius:3px; overflow:hidden;}
            #progress-fill {width:0; height:100%; background:#007cba; transition: width 0.3s;}
            .file-list table {table-layout: fixed; width: 100%;}
            .file-list td.path {word-wrap: break-word;}
            .connection-status {padding: .5em; margin: 1em 0; border-radius: 3px;}
            .connection-success {background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;}
            .connection-error {background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;}
        ";
        wp_add_inline_style('file-transfer-css', $custom_css);
    }

    /**
     * Admin page display
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized access');
        
        $files = $this->get_files($this->base_dir);
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('transfer_nonce');
        $conn_nonce = wp_create_nonce('ftp_conn_nonce');
        ?>
        <div class="wrap">
            <h1>File Transfer (FTP/SFTP) - Chunked Upload with Real-Time Progress</h1>
            
            <input id="file-search" placeholder="Search files..." style="width:300px; margin-bottom:10px;">
            <div class="file-list" style="max-height:300px; overflow:auto; border:1px solid #ddd; margin-bottom:15px;">
                <table class="widefat fixed" id="file-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="select_all"></th>
                            <th>Path</th>
                        </tr>
                    </thead>
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

            <div class="connection-form">
                <h2>Connection Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Protocol</th>
                        <td>
                            <select id="protocol">
                                <option value="ftp">FTP</option>
                                <option value="sftp">SFTP</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Host</th>
                        <td><input type="text" id="host" placeholder="example.com" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Port</th>
                        <td><input type="number" id="port" placeholder="21 or 22" class="small-text"></td>
                    </tr>
                    <tr>
                        <th>User</th>
                        <td><input type="text" id="user" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>Password/Key</th>
                        <td>
                            <input type="text" id="pass" placeholder="password or private key path" class="regular-text">
                            <p class="description">For SFTP: Enter password or path to private key file</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Remote Directory</th>
                        <td>
                            <input type="text" id="remote_dir" placeholder="/path/to/dir" class="regular-text">
                            <p class="description">Path on remote server where files will be uploaded</p>
                        </td>
                    </tr>
                </table>

                <div class="connection-actions">
                    <button id="test-connection" class="button">Test Connection</button>
                    <button id="save-ftp" class="button">Save Connection</button>
                    <button id="reset-ftp" class="button">Reset</button>
                </div>

                <div id="connection-status" style="display: none;"></div>
            </div>

            <div class="transfer-actions" style="margin: 20px 0;">
                <button id="start" class="button button-primary button-large">Start Transfer</button>
                <span id="selected-count" style="margin-left: 10px;"></span>
            </div>

            <h2>Connection Log</h2>
            <div id="log-area"></div>
            
            <h2>Progress</h2>
            <div id="progress-bar"><div id="progress-fill"></div></div>
            <div id="speed-info" style="margin-top:5px;font-family: monospace;"></div>
        </div>

        <script>
        (function($){
            // Update selected count
            function updateSelectedCount() {
                var count = $('input[name="files[]"]:checked').length;
                $('#selected-count').text(count + ' file(s) selected');
            }
            
            // Select all checkbox handler
            $('#select_all').on('change', function(){
                $('input[name="files[]"]').prop('checked', this.checked);
                updateSelectedCount();
            });

            // Individual checkbox handler
            $('input[name="files[]"]').on('change', function() {
                updateSelectedCount();
            });

            // Update the count on page load
            updateSelectedCount();

            // File search functionality
            $('#file-search').on('keyup', function(){
                var term = $(this).val().toLowerCase();
                $('#file-table tbody tr').each(function(){
                    var t = $(this).find('.path').text().toLowerCase();
                    $(this).toggle(term === '' || t.indexOf(term) !== -1);
                });
            });

            // Log messages with scrolling
            function log(text, isError) {
                var color = isError ? '#ff0000' : '#000000';
                $('#log-area').append('<div style="color:'+color+'">'+text+'</div>')
                              .scrollTop($('#log-area')[0].scrollHeight);
            }

            // Test connection
            function testConnection(protocol, host, port, user, pass, callback) {
                $('#connection-status').removeClass('connection-success connection-error').hide();
                log('Testing connection...');
                
                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'test_ftp_conn',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    protocol: protocol,
                    host: host,
                    port: port,
                    user: user,
                    pass: pass
                }, function(res) {
                    callback(res);
                }, 'json').fail(function(jqXHR) {
                    callback({
                        success: false, 
                        data: jqXHR.responseJSON?.data || 'Connection error: ' + jqXHR.status
                    });
                });
            }
            
            // Test connection button handler
            $('#test-connection').on('click', function() {
                var protocol = $('#protocol').val(),
                    host = $('#host').val().trim(),
                    port = parseInt($('#port').val()),
                    user = $('#user').val().trim(),
                    pass = $('#pass').val().trim();
                
                if (!host || isNaN(port) || !user) {
                    $('#connection-status').removeClass('connection-success')
                        .addClass('connection-error')
                        .html('Please fill all required connection fields')
                        .show();
                    return;
                }
                
                testConnection(protocol, host, port, user, pass, function(res) {
                    if (res.success) {
                        $('#connection-status').removeClass('connection-error')
                            .addClass('connection-success')
                            .html('Connection successful!')
                            .show();
                        log('Connection test successful.');
                    } else {
                        $('#connection-status').removeClass('connection-success')
                            .addClass('connection-error')
                            .html('Connection failed: ' + (res.data || 'Unknown error'))
                            .show();
                        log('Connection test failed: ' + (res.data || 'Unknown error'), true);
                    }
                });
            });

            // Start transfer button handler
            $('#start').on('click', function(){
                var sel = $('input[name="files[]"]:checked'),
                    files = sel.map(function(){ return this.value; }).get();
                    
                if(!files.length) {
                    return alert('Please select at least one file to transfer.');
                }

                var protocol = $('#protocol').val(),
                    host = $('#host').val().trim(),
                    port = parseInt($('#port').val()),
                    user = $('#user').val().trim(),
                    pass = $('#pass').val().trim(),
                    remote_dir = $('#remote_dir').val().trim();

                if (!host || isNaN(port) || !user || !remote_dir) {
                    return alert('Please fill all required connection fields.');
                }

                $('#log-area').empty().append('<div>Testing connection before starting transfer...</div>');
                $('#progress-fill').css('width', '0');
                $('#speed-info').text('');

                testConnection(protocol, host, port, user, pass, function(res) {
                    if(res.success) {
                        log('Connection successful. Starting transfer...');
                        
                        var totalFiles = files.length,
                            currentFileIndex = 0,
                            totalTransferred = 0,
                            globalStartTime = Date.now();

                        function updateOverallStatus() {
                            var now = Date.now();
                            var elapsed = (now - globalStartTime) / 1000;
                            var overall = Math.round((currentFileIndex / totalFiles) * 100);
                            
                            if (elapsed > 0 && totalTransferred > 0) {
                                var speed = (totalTransferred / 1024 / elapsed).toFixed(2);
                                $('#speed-info').html(
                                    'Overall progress: ' + currentFileIndex + '/' + totalFiles + 
                                    ' files (' + overall + '%) at avg ' + speed + ' KB/s'
                                );
                            }
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
                                    url: '<?php echo esc_js($ajax_url); ?>',
                                    method: 'POST',
                                    dataType: 'json',
                                    timeout: 120000, // 2 minute timeout
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
                                            var chunkSize = res.data.new_offset - offset;
                                            offset = res.data.new_offset;
                                            totalTransferred += chunkSize;
                                            
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
                                                updateOverallStatus();
                                                
                                                if (currentFileIndex < totalFiles) {
                                                    uploadFileChunked(files[currentFileIndex]);
                                                } else {
                                                    var totalTime = (Date.now() - globalStartTime) / 1000;
                                                    log('All files transferred successfully in ' + totalTime.toFixed(1) + ' seconds!');
                                                }
                                            }
                                            retries = 0;
                                        } else {
                                            log('Error uploading ' + file + ': ' + (res.data?.message || res.data), true);
                                            if (retries < maxRetries) {
                                                retries++;
                                                log('Retry ' + retries + '/' + maxRetries + ' in ' + retryDelay + 's...');
                                                setTimeout(uploadNextChunk, retryDelay * 1000);
                                            } else {
                                                log('Failed to upload ' + file + ' after ' + maxRetries + ' retries. Moving to next file.', true);
                                                currentFileIndex++;
                                                updateOverallStatus();
                                                
                                                if (currentFileIndex < totalFiles) {
                                                    uploadFileChunked(files[currentFileIndex]);
                                                } else {
                                                    log('Transfer completed with errors.');
                                                }
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
                                            log('Retry ' + retries + '/' + maxRetries + ' in ' + retryDelay + 's...', true);
                                            setTimeout(uploadNextChunk, retryDelay * 1000);
                                        } else {
                                            log('Failed to upload ' + file + ' after ' + maxRetries + ' retries. Moving to next file.', true);
                                            currentFileIndex++;
                                            updateOverallStatus();
                                            
                                            if (currentFileIndex < totalFiles) {
                                                uploadFileChunked(files[currentFileIndex]);
                                            } else {
                                                log('Transfer completed with errors.');
                                            }
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

            // Load saved connection data on page load
            $(function() {
                $.post('<?php echo esc_js($ajax_url); ?>', {
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
                    
                    // Set default port based on protocol if not set
                    if (!$('#port').val()) {
                        $('#port').val($('#protocol').val() === 'ftp' ? '21' : '22');
                    }
                }, 'json');
                
                // Set port when protocol changes
                $('#protocol').on('change', function() {
                    if ($('#port').val() === '21' || $('#port').val() === '22' || !$('#port').val()) {
                        $('#port').val($(this).val() === 'ftp' ? '21' : '22');
                    }
                });
            });

            // Save connection button handler
            $('#save-ftp').on('click', function() {
                var data = {
                    protocol: $('#protocol').val(),
                    host: $('#host').val(),
                    port: $('#port').val(),
                    user: $('#user').val(),
                    pass: $('#pass').val(),
                    remote_dir: $('#remote_dir').val()
                };
                
                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'save_ftp_conn',
                    nonce: '<?php echo esc_js($conn_nonce); ?>',
                    data: data
                }, function(res) {
                    if(res.success) {
                        $('#connection-status').removeClass('connection-error')
                            .addClass('connection-success')
                            .html('Connection data saved!')
                            .show();
                    } else {
                        $('#connection-status').removeClass('connection-success')
                            .addClass('connection-error')
                            .html('Failed to save: ' + (res.data || 'Unknown error'))
                            .show();
                    }
                }, 'json');
            });

            // Reset connection button handler
            $('#reset-ftp').on('click', function() {
                if (confirm('Reset connection settings?')) {
                    $.post('<?php echo esc_js($ajax_url); ?>', {
                        action: 'reset_ftp_conn',
                        nonce: '<?php echo esc_js($conn_nonce); ?>'
                    }, function(res) {
                        $('#protocol').val('sftp');
                        $('#host, #user, #pass, #remote_dir').val('');
                        $('#port').val('22');
                        $('#connection-status').removeClass('connection-error')
                            .addClass('connection-success')
                            .html('Connection data reset.')
                            .show();
                    }, 'json');
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Handle file chunk transfer
     */
    public function ajax_transfer_file_chunk() {
        $this->log('ajax_transfer_file_chunk called');
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        check_ajax_referer('transfer_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            $this->log('Permission denied');
            wp_send_json_error('No permission.');
        }

        try {
            $required = ['protocol', 'host', 'port', 'user', 'remote_dir', 'file'];
            foreach ($required as $key) {
                if (empty($_POST[$key])) throw new Exception("Missing parameter: $key");
            }

            $p = $_POST;
            $protocol = sanitize_text_field($p['protocol']);
            $host = sanitize_text_field($p['host']);
            $port = intval($p['port']);
            $user = sanitize_text_field($p['user']);
            $pass = sanitize_text_field($p['pass'] ?? '');
            $remote_dir = rtrim(sanitize_text_field($p['remote_dir']), '/');
            $file = ltrim(sanitize_text_field($p['file']), '/');
            $offset = intval($p['offset'] ?? 0);
            $chunk_size = intval($p['chunk_size'] ?? $this->chunk_size);

            $local_path = ABSPATH . $file;
            if (!file_exists($local_path)) {
                $this->log("Local file missing: $local_path");
                throw new Exception("Local file missing: $file");
            }

            $filesize = filesize($local_path);
            if ($filesize === 0) {
                $this->log("File is empty: $local_path");
                throw new Exception("File is empty: $file");
            }

            // Normalize paths for remote system
            $remote_file = str_replace('\\', '/', $file);
            $remote_path = $remote_dir . '/' . $remote_file;
            $remote_dir_path = dirname($remote_path);

            if ($protocol === 'sftp') {
                // Handle SFTP transfer
                return $this->handle_sftp_transfer($host, $port, $user, $pass, $remote_dir_path, $remote_path, $local_path, $offset, $chunk_size, $filesize);
            } else if ($protocol === 'ftp') {
                // Handle FTP transfer
                return $this->handle_ftp_transfer($host, $port, $user, $pass, $remote_dir_path, $remote_path, $local_path, $offset, $chunk_size, $filesize);
            } else {
                throw new Exception("Unsupported protocol: $protocol");
            }
        } catch (Exception $e) {
            $error = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'server' => "PHP " . phpversion() . " | " . $_SERVER['SERVER_SOFTWARE'],
                'trace' => $e->getTraceAsString()
            ];
            $this->log("ERROR: " . print_r($error, true));
            wp_send_json_error($error);
        }
    }

    /**
     * Handle SFTP transfer
     */
    private function handle_sftp_transfer($host, $port, $user, $pass, $remote_dir_path, $remote_path, $local_path, $offset, $chunk_size, $filesize) {
        $sftp = new SFTP($host, $port, 30);
        $sftp->setTimeout(60); // Increase timeout for larger files
        if (method_exists($sftp, 'setKeepAlive')) {
            $sftp->setKeepAlive(10);
        }

        // Login with retry
        $logged_in = false;
        $auth_error = '';
        
        for ($i = 0; $i < $this->max_retries; $i++) {
            try {
                if (file_exists($pass) && !empty($pass)) {
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
                $this->log("SFTP Connection Error (Attempt ".($i+1)."): ".$auth_error);
                sleep($this->retry_delay);
            }
        }
        
        if (!$logged_in) {
            throw new Exception("SFTP authentication failed: ".$auth_error);
        }

        // Create remote directory path if it doesn't exist
        $this->create_remote_dirs($sftp, $remote_dir_path, 'sftp');

        // Check if the part file exists and get its size
        $part_file = $remote_path . '.part';
        $remote_size = $sftp->file_exists($part_file) ? $sftp->filesize($part_file) : 0;
        
        // Adjust offset if the remote part file is larger
        if ($remote_size > $offset) {
            $this->log("Adjusting offset from $offset to $remote_size based on remote part file size");
            $offset = $remote_size;
        }

        // Create temporary file for chunk
        $temp_file = tempnam(sys_get_temp_dir(), 'sftp_chunk');
        
        try {
            // Open the local file for reading
            $fp = fopen($local_path, 'rb');
            if ($fp === false) {
                throw new Exception("Could not open local file for reading: $local_path");
            }
            
            // Seek to the current offset
            if (fseek($fp, $offset) !== 0) {
                throw new Exception("Could not seek to position $offset in file");
            }
            
            // Read chunk
            $chunk = fread($fp, min($chunk_size, $filesize - $offset));
            fclose($fp);
            
            if ($chunk === false || strlen($chunk) === 0) {
                throw new Exception("Failed to read chunk from local file at offset $offset");
            }
            
            // Write chunk to temp file
            if (file_put_contents($temp_file, $chunk) === false) {
                throw new Exception("Failed to write chunk to temp file");
            }
            
            // Upload the chunk
            $success = false;
            $upload_error = '';
            
            for ($i = 0; $i < $this->max_retries; $i++) {
                try {
                    // Ensure connection is still active
                    if (!$sftp->isConnected()) {
                        $this->log("Reconnecting to SFTP...");
                        $sftp = new SFTP($host, $port, 30);
                        $sftp->setTimeout(60);
                        if (method_exists($sftp, 'setKeepAlive')) {
                            $sftp->setKeepAlive(10);
                        }
                        if (file_exists($pass) && !empty($pass)) {
                            $key = PublicKeyLoader::load(file_get_contents($pass));
                            $logged_in = $sftp->login($user, $key);
                        } else {
                            $logged_in = $sftp->login($user, $pass);
                        }
                        if (!$logged_in) {
                            throw new Exception("Failed to reconnect to SFTP");
                        }
                    }
                    
                    // For the first chunk, create a new file; otherwise append
                    $mode = $offset === 0 ? SFTP::SOURCE_LOCAL_FILE : SFTP::SOURCE_LOCAL_FILE | SFTP::RESUME;
                    
                    if ($sftp->put($part_file, $temp_file, $mode)) {
                        $success = true;
                        break;
                    }
                    
                    $upload_error = implode(', ', $sftp->getSFTPErrors());
                    $this->log("SFTP upload attempt $i failed: $upload_error");
                    sleep($this->retry_delay);
                } catch (Exception $e) {
                    $upload_error = $e->getMessage();
                    $this->log("SFTP upload exception on attempt $i: $upload_error");
                    sleep($this->retry_delay);
                }
            }
            
            if (!$success) {
                throw new Exception("Failed to upload chunk after {$this->max_retries} attempts: $upload_error");
            }
            
            // If this was the final chunk, rename the file
            if (($offset + strlen($chunk)) >= $filesize) {
                $this->log("Final chunk uploaded, renaming from $part_file to $remote_path");
                
                // Delete existing file if it exists
                if ($sftp->file_exists($remote_path)) {
                    $sftp->delete($remote_path);
                }
                
                // Rename the part file to the final filename
                $renamed = false;
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if (!$sftp->isConnected()) {
                            $sftp = new SFTP($host, $port, 30);
                            $sftp->setTimeout(60);
                            if (method_exists($sftp, 'setKeepAlive')) {
                                $sftp->setKeepAlive(10);
                            }
                            if (file_exists($pass) && !empty($pass)) {
                                $key = PublicKeyLoader::load(file_get_contents($pass));
                                $logged_in = $sftp->login($user, $key);
                            } else {
                                $logged_in = $sftp->login($user, $pass);
                            }
                            if (!$logged_in) {
                                throw new Exception("Failed to reconnect for rename operation");
                            }
                        }

                        if (!$sftp->file_exists($part_file)) {
                            throw new Exception("Part file missing before rename");
                        }

                        if ($sftp->rename($part_file, $remote_path)) {
                            $renamed = true;
                            break;
                        }

                        $this->log("Rename attempt $i failed: " . implode(', ', $sftp->getSFTPErrors()));
                        sleep($this->retry_delay);
                    } catch (Exception $e) {
                        $this->log("Rename attempt $i exception: " . $e->getMessage());
                        sleep($this->retry_delay);
                    }
                }

                if (!$renamed) {
                    throw new Exception("Failed to rename part file after {$this->max_retries} attempts");
                }

                $this->log("File completely uploaded and renamed successfully");
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

    /**
     * Handle FTP transfer
     */
    private function handle_ftp_transfer($host, $port, $user, $pass, $remote_dir_path, $remote_path, $local_path, $offset, $chunk_size, $filesize) {
        // Standard FTP - add implementation since it's missing in the original
        $ftp = new FTP();
        $ftp->setTimeout(60); // 60 second timeout

        // Connect to FTP server
        $connected = false;
        $conn_error = '';
        
        for ($i = 0; $i < $this->max_retries; $i++) {
            try {
                if ($ftp->connect($host, $port)) {
                    $connected = true;
                    break;
                }
            } catch (Exception $e) {
                $conn_error = $e->getMessage();
                $this->log("FTP Connection Error (Attempt " . ($i+1) . "): " . $conn_error);
                sleep($this->retry_delay);
            }
        }
        
        if (!$connected) {
            throw new Exception("Failed to connect to FTP server: " . $conn_error);
        }
        
        // Login to FTP server
        $logged_in = false;
        $login_error = '';
        
        for ($i = 0; $i < $this->max_retries; $i++) {
            try {
                if ($ftp->login($user, $pass)) {
                    $logged_in = true;
                    break;
                }
            } catch (Exception $e) {
                $login_error = $e->getMessage();
                $this->log("FTP Login Error (Attempt " . ($i+1) . "): " . $login_error);
                sleep($this->retry_delay);
            }
        }
        
        if (!$logged_in) {
            throw new Exception("Failed to login to FTP server: " . $login_error);
        }
        
        // Enable passive mode for better compatibility with firewalls
        $ftp->pasv(true);
        
        // Create remote directory structure
        $this->create_remote_dirs($ftp, $remote_dir_path, 'ftp');
        
        // Part file path
        $part_file = $remote_path . '.part';
        
        // Check if we need to resume upload
        $remote_size = 0;
        
        try {
            $remote_size = $ftp->size($part_file);
            if ($remote_size === -1) {
                $remote_size = 0;
            }
        } catch (Exception $e) {
            $remote_size = 0;
        }
        
        // Adjust offset if the remote part file is larger
        if ($remote_size > $offset) {
            $this->log("Adjusting offset from $offset to $remote_size based on remote part file size");
            $offset = $remote_size;
        }
        
        // Upload the chunk
        try {
            // Open local file
            $fp = fopen($local_path, 'rb');
            if ($fp === false) {
                throw new Exception("Could not open local file for reading: $local_path");
            }
            
            // Seek to the current offset
            if (fseek($fp, $offset) !== 0) {
                throw new Exception("Could not seek to position $offset in file");
            }
            
            // Create temp file for chunk
            $temp_file = tempnam(sys_get_temp_dir(), 'ftp_chunk');
            $temp_fp = fopen($temp_file, 'wb');
            
            if (!$temp_fp) {
                throw new Exception("Failed to create temporary file");
            }
            
            // Copy chunk to temp file
            $bytes_written = 0;
            $bytes_to_write = min($chunk_size, $filesize - $offset);
            
            while ($bytes_written < $bytes_to_write) {
                $buffer = fread($fp, 8192); // Read in 8KB blocks
                if ($buffer === false) {
                    break;
                }
                
                $buffer_size = strlen($buffer);
                if ($bytes_written + $buffer_size > $bytes_to_write) {
                    $buffer = substr($buffer, 0, $bytes_to_write - $bytes_written);
                    $buffer_size = strlen($buffer);
                }
                
                $written = fwrite($temp_fp, $buffer);
                if ($written === false || $written != $buffer_size) {
                    throw new Exception("Failed to write to temp file");
                }
                
                $bytes_written += $written;
            }
            
            fclose($fp);
            fclose($temp_fp);
            
            // For the first chunk, create a new file
            if ($offset === 0) {
                $ftp_mode = FTP_BINARY;
                $upload_success = false;
                
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if ($ftp->put($part_file, $temp_file, FTP_BINARY)) {
                            $upload_success = true;
                            break;
                        }
                        $this->log("FTP upload attempt $i failed");
                        sleep($this->retry_delay);
                    } catch (Exception $e) {
                        $this->log("FTP upload exception on attempt $i: " . $e->getMessage());
                        sleep($this->retry_delay);
                    }
                }
                
                if (!$upload_success) {
                    throw new Exception("Failed to upload initial chunk after {$this->max_retries} attempts");
                }
            } else {
                // For subsequent chunks, we need to append
                // Since FTP doesn't natively support append, we need to download the file,
                // append locally, then upload the whole thing again
                // This is inefficient but necessary for standard FTP
                
                $existing_file = tempnam(sys_get_temp_dir(), 'ftp_existing');
                
                try {
                    // Download the existing part file
                    if (!$ftp->get($existing_file, $part_file, FTP_BINARY)) {
                        throw new Exception("Failed to download existing part file");
                    }
                    
                    // Append the new chunk to the downloaded file
                    $existing_fp = fopen($existing_file, 'ab');
                    $new_chunk_fp = fopen($temp_file, 'rb');
                    
                    if (!$existing_fp || !$new_chunk_fp) {
                        throw new Exception("Failed to open temp files for appending");
                    }
                    
                    while (!feof($new_chunk_fp)) {
                        $buffer = fread($new_chunk_fp, 8192);
                        if ($buffer === false) {
                            break;
                        }
                        
                        if (fwrite($existing_fp, $buffer) === false) {
                            throw new Exception("Failed to append chunk to existing file");
                        }
                    }
                    
                    fclose($existing_fp);
                    fclose($new_chunk_fp);
                    
                    // Upload the combined file
                    $upload_success = false;
                    
                    for ($i = 0; $i < $this->max_retries; $i++) {
                        try {
                            if ($ftp->put($part_file, $existing_file, FTP_BINARY)) {
                                $upload_success = true;
                                break;
                            }
                            $this->log("FTP upload attempt $i failed");
                            sleep($this->retry_delay);
                        } catch (Exception $e) {
                            $this->log("FTP upload exception on attempt $i: " . $e->getMessage());
                            sleep($this->retry_delay);
                        }
                    }
                    
                    if (!$upload_success) {
                        throw new Exception("Failed to upload appended chunk after {$this->max_retries} attempts");
                    }
                } finally {
                    // Clean up the temporary download file
                    if (file_exists($existing_file)) {
                        @unlink($existing_file);
                    }
                }
            }
            
            // If this is the final chunk, rename the file
            if (($offset + $bytes_written) >= $filesize) {
                $this->log("Final chunk uploaded, renaming from $part_file to $remote_path");
                
                // Delete the destination file if it exists
                try {
                    $ftp->delete($remote_path);
                } catch (Exception $e) {
                    // Ignore if file doesn't exist
                }
                
                // Rename the part file to the final filename
                $renamed = false;
                
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if ($ftp->rename($part_file, $remote_path)) {
                            $renamed = true;
                            break;
                        }
                        $this->log("FTP rename attempt $i failed");
                        sleep($this->retry_delay);
                    } catch (Exception $e) {
                        $this->log("FTP rename exception on attempt $i: " . $e->getMessage());
                        sleep($this->retry_delay);
                    }
                }
                
                if (!$renamed) {
                    throw new Exception("Failed to rename part file after {$this->max_retries} attempts");
                }
                
                $this->log("File completely uploaded and renamed successfully");
            }
            
            // Return success with new offset
            wp_send_json_success([
                'filesize' => $filesize,
                'chunk_size' => $bytes_written,
                'new_offset' => $offset + $bytes_written
            ]);
            
        } catch (Exception $e) {
            throw $e;
        } finally {
            // Clean up temp file
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            
            // Close the FTP connection
            $ftp->close();
        }
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_ftp_conn() {
        check_ajax_referer('transfer_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission.');
        }

        try {
            $required = ['protocol', 'host', 'port', 'user'];
            foreach ($required as $key) {
                if (empty($_POST[$key])) {
                    throw new Exception("Missing parameter: $key");
                }
            }
            
            $protocol = sanitize_text_field($_POST['protocol']);
            $host = sanitize_text_field($_POST['host']);
            $port = intval($_POST['port']);
            $user = sanitize_text_field($_POST['user']);
            $pass = sanitize_text_field($_POST['pass'] ?? '');
            
            if ($protocol === 'sftp') {
                // Test SFTP connection
                $sftp = new SFTP($host, $port, 10);
                $sftp->setTimeout(10);
                
                $logged_in = false;
                for ($i = 0; $i < $this->max_retries; $i++) {
                    try {
                        if (file_exists($pass) && !empty($pass)) {
                            $key = PublicKeyLoader::load(file_get_contents($pass));
                            $logged_in = $sftp->login($user, $key);
                        } else {
                            $logged_in = $sftp->login($user, $pass);
                        }
                        
                        if ($logged_in) {
                            break;
                        }
                        
                        sleep($this->retry_delay);
                    } catch (Exception $e) {
                        $this->log("SFTP Test Login Attempt $i failed: " . $e->getMessage());
                        sleep($this->retry_delay);
                    }
                }
                
                if (!$logged_in) {
                    throw new Exception('SFTP login failed: ' . 
                        (method_exists($sftp, 'getLastError') ? $sftp->getLastError() : 'Authentication failed'));
                }
                
                // Test file operation if possible
                try {
                    $sftp_version = $sftp->getServerIdentification() ?: 'Unknown';
                    $this->log("SFTP connection successful. Server: $sftp_version");
                } catch (Exception $e) {
                    $this->log("SFTP info retrieval error: " . $e->getMessage());
                }
                
                wp_send_json_success('SFTP connection successful');
                
            } else if ($protocol === 'ftp') {
                // Test FTP connection
                $ftp = new FTP();
                $ftp->setTimeout(10);
                
                if (!$ftp->connect($host, $port)) {
                    throw new Exception('FTP connection failed');
                }
                
                if (!$ftp->login($user, $pass)) {
                    throw new Exception('FTP login failed: Invalid credentials');
                }
                
                // Enable passive mode for better compatibility
                $ftp->pasv(true);
                
                // Test file operation if possible
                try {
                    $system_type = $ftp->systype() ?: 'Unknown';
                    $this->log("FTP connection successful. System: $system_type");
                } catch (Exception $e) {
                    $this->log("FTP info retrieval error: " . $e->getMessage());
                }
                
                // Close the connection
                $ftp->close();
                
                wp_send_json_success('FTP connection successful');
            } else {
                throw new Exception("Unsupported protocol: $protocol");
            }
        } catch (Exception $e) {
            $this->log("Connection test failed: " . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler for saving connection
     */
    public function ajax_save_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission.');
        }
        
        $data = isset($_POST['data']) ? array_map('sanitize_text_field', $_POST['data']) : [];
        if (!is_array($data)) {
            wp_send_json_error('Invalid data format');
        }
        
        update_user_meta(get_current_user_id(), '_ftp_sftp_conn', $data);
        wp_send_json_success();
    }

    /**
     * AJAX handler for loading connection
     */
    public function ajax_load_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission.');
        }
        
        $data = get_user_meta(get_current_user_id(), '_ftp_sftp_conn', true);
        wp_send_json_success($data ?: []);
    }

    /**
     * AJAX handler for resetting connection
     */
    public function ajax_reset_ftp_conn() {
        check_ajax_referer('ftp_conn_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission.');
        }
        
        delete_user_meta(get_current_user_id(), '_ftp_sftp_conn');
        wp_send_json_success();
    }
}

// Initialize the plugin
new FTP_SFTP_File_Transfer_Plugin();