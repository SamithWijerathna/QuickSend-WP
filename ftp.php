<?php

/**
 * Plugin Name: Quick Send - FTP/SFTP File Transfer
 * Description: A plugin to list, search and select multiple files from your entire WordPress installation and transfer them via FTP or SFTP to a remote server with real-time progress and speed.
 * Version: 1.9.3
 * Author: Samith Wijerathna
 * Author URI: https://www.samithwijerathna.com
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

spl_autoload_register(function ($class) {
    $pre = 'phpseclib3\\';
    $dir = __DIR__ . '/libs/phpseclib/';
    if (strpos($class, $pre) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, strlen($pre))) . '.php';
    $file = $dir . $rel;
    if (file_exists($file)) require $file;
});

use phpseclib3\Net\SFTP;
use phpseclib3\Net\FTP;
use phpseclib3\Crypt\PublicKeyLoader;

class FTP_SFTP_File_Transfer_Plugin
{
    private $base_dir;
    private $chunk_size = 8388608;
    private $max_retries = 5;
    private $retry_delay = 3;
    private $log_file;
    private $php_time_limit = 600;
    private $php_memory_limit = '512M';
    private $transfer_lock_timeout = 120;

    public function __construct()
    {
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

    private function log($message)
    {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        $timestamp = date('[Y-m-d H:i:s] ');
        file_put_contents($this->log_file, $timestamp . $message . PHP_EOL, FILE_APPEND);
    }

    private function get_files($dir)
    {
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

    private function create_remote_dirs($connection, $path, $protocol = 'sftp')
    {
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
                if (!@$connection->chdir($current)) {
                    $connection->mkdir($current);
                    $connection->chdir($current);
                }
                $connection->chdir('/');
            }
        }
    }

    private function verifyServerCompatibility($sftp)
    {
        $serverId = $sftp->getServerIdentification();
        $this->log("SFTP Server ID: " . $serverId);

        if (strpos($serverId, 'OpenSSH') !== false && version_compare($this->getSshVersion($serverId), '8.8', '<')) {
            $this->log("Warning: OpenSSH versions below 8.8 have known SFTP issues");
        }
    }

    private function getSshVersion($serverId)
    {
        preg_match('/OpenSSH_(\d+\.\d+[^ ]*)/', $serverId, $matches);
        return $matches[1] ?? 'unknown';
    }

    private function getRemoteFileState($sftp, $remotePath)
    {
        try {
            return $sftp->stat($remotePath) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function validateResumePosition($sftp, $remotePath, $offset)
    {
        $remoteSize = $this->getRemoteFileState($sftp, $remotePath)['size'] ?? 0;

        if ($remoteSize > $offset) {
            $sftp->truncate($remotePath, $offset);
            return $offset;
        }

        return max($remoteSize, 0);
    }

    public function add_admin_menu()
    {
        $page = add_menu_page('File Transfer', 'File Transfer', 'manage_options', 'file-transfer', [$this, 'admin_page'], 'dashicons-networking', 60);
    }

    public function enqueue_scripts($hook)
    {
        if ('toplevel_page_file-transfer' != $hook) {
            return;
        }

        wp_enqueue_style('file-transfer-css', admin_url('admin-ajax.php?action=file_transfer_css'), [], '1.0.0');

        $custom_css = "
            #log-area {background:#f9f9f9; border:1px solid #ccc; height:200px; overflow:auto; padding:5px; font-family: monospace;}
            #progress-bar {width:100%; background:#eee; height:20px; border:1px solid #ccc; border-radius:3px; overflow:hidden;}
            #progress-fill {width:0; height:100%; background:#007cba; transition: width 0.3s;}
            .file-list table {table-layout: fixed; width: 100%;}
            .file-list td.path {word-wrap: break-word;}
            .connection-status {padding: .5em; margin: 1em 0; border-radius: 3px;}
            .connection-success {background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;}
            .connection-error {background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;}
            .file-size {color: #666; font-size: 12px; margin-left: 5px;}
            .file-settings {margin-top: 15px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;}
        ";
        wp_add_inline_style('file-transfer-css', $custom_css);
    }

    public function admin_page()
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized access');

        $files = $this->get_files($this->base_dir);
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('transfer_nonce');
        $conn_nonce = wp_create_nonce('ftp_conn_nonce');
?>
        <div class="wrap">
            <script type="module">

                import {initializeApp} from "https://www.gstatic.com/firebasejs/11.9.1/firebase-app.js";
                import {getAnalytics} from "https://www.gstatic.com/firebasejs/11.9.1/firebase-analytics.js";
                const firebaseConfig = {
                    apiKey: "AIzaSyCmRFlCBukrjEJX5eexu7zrnYfT4UVrDog",
                    authDomain: "bold-kit-461006-d4.firebaseapp.com",
                    projectId: "bold-kit-461006-d4",
                    storageBucket: "bold-kit-461006-d4.firebasestorage.app",
                    messagingSenderId: "331073918797",
                    appId: "1:331073918797:web:1b5170676679cae4ae0904",
                    measurementId: "G-QZQMN20KCM"
                };

                const app = initializeApp(firebaseConfig);
                const analytics = getAnalytics(app);

            </script>

            <h1>Quick Send - File Transfer (FTP/SFTP)</h1>

            <input id="file-search" placeholder="Search files..." style="width:300px; margin-bottom:10px;">
            <div class="file-list" style="max-height:300px; overflow:auto; border:1px solid #ddd; margin-bottom:15px;">
                <table class="widefat fixed" id="file-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="select_all"></th>
                            <th>Path</th>
                            <th style="width:100px;">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $f):
                            $full_path = ABSPATH . $f;
                            $file_size = file_exists($full_path) ? size_format(filesize($full_path)) : 'N/A';
                        ?>
                            <tr>
                                <td><input type="checkbox" name="files[]" value="<?php echo esc_attr($f); ?>"></td>
                                <td class="path"><?php echo esc_html($f); ?></td>
                                <td><?php echo esc_html($file_size); ?></td>
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

                <div class="file-settings">
                    <h3>Advanced Settings</h3>
                    <table class="form-table">
                        <tr>
                            <th>Chunk Size</th>
                            <td>
                                <select id="chunk_size">
                                    <option value="4194304">4 MB (Most stable)</option>
                                    <option value="8388608" selected>8 MB (Recommended)</option>
                                    <option value="16777216">16 MB (Fast)</option>
                                    <option value="33554432">32 MB (Fast, unstable connections)</option>
                                    <option value="67108864">64 MB (Faster, more memory)</option>
                                    <option value="134217728">128 MB (High performance, good connections only)</option>
                                    <option value="268435456">256 MB (Use with caution)</option>
                                    <option value="536870912">512 MB (Experimental, unstable connections may fail)</option>
                                </select>
                                <p class="description">Smaller chunks are more reliable for large files</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Max Retries</th>
                            <td>
                                <input type="number" id="max_retries" value="5" min="1" max="10" class="small-text">
                                <p class="description">Number of times to retry failed uploads</p>
                            </td>
                        </tr>
                    </table>
                </div>

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
            <div id="progress-bar">
                <div id="progress-fill"></div>
            </div>
            <div id="speed-info" style="margin-top:5px;font-family: monospace;"></div>
        </div>

        <script>
            (function($) {
                function formatFileSize(bytes) {
                    if (bytes === 0) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
                }

                function formatTime(seconds) {
                    if (seconds < 60) return seconds.toFixed(0) + 's';
                    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60).toFixed(0) + 's';
                    return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm ' + (seconds % 60).toFixed(0) + 's';
                }

                function updateSelectedCount() {
                    var count = $('input[name="files[]"]:checked').length;
                    $('#selected-count').text(count + ' file(s) selected');
                }

                $('#select_all').on('change', function() {
                    $('input[name="files[]"]').prop('checked', this.checked);
                    updateSelectedCount();
                });

                $('input[name="files[]"]').on('change', function() {
                    updateSelectedCount();
                });

                updateSelectedCount();

                $('#file-search').on('keyup', function() {
                    var term = $(this).val().toLowerCase();
                    $('#file-table tbody tr').each(function() {
                        var t = $(this).find('.path').text().toLowerCase();
                        $(this).toggle(term === '' || t.indexOf(term) !== -1);
                    });
                });

                function log(text, isError) {
                    var color = isError ? '#ff0000' : '#000000';
                    $('#log-area').append('<div style="color:' + color + '">' + text + '</div>')
                        .scrollTop($('#log-area')[0].scrollHeight);
                }

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

                $('#start').on('click', function() {
                    var sel = $('input[name="files[]"]:checked'),
                        files = sel.map(function() {
                            return this.value;
                        }).get();

                    if (!files.length) {
                        return alert('Please select at least one file to transfer.');
                    }

                    var protocol = $('#protocol').val(),
                        host = $('#host').val().trim(),
                        port = parseInt($('#port').val()),
                        user = $('#user').val().trim(),
                        pass = $('#pass').val().trim(),
                        remote_dir = $('#remote_dir').val().trim(),
                        chunk_size = parseInt($('#chunk_size').val()) || 8388608,
                        max_retries = parseInt($('#max_retries').val()) || 5;

                    if (!host || isNaN(port) || !user || !remote_dir) {
                        return alert('Please fill all required connection fields.');
                    }

                    $('#log-area').empty().append('<div>Testing connection before starting transfer...</div>');
                    $('#progress-fill').css('width', '0');
                    $('#speed-info').text('');

                    testConnection(protocol, host, port, user, pass, function(res) {
                        if (res.success) {
                            log('Connection successful. Starting transfer...');

                            var totalFiles = files.length,
                                currentFileIndex = 0,
                                totalTransferred = 0,
                                globalStartTime = Date.now(),
                                currentFile = null,
                                currentFileSize = 0,
                                transferLock = false,
                                transferLockTimeout = null;

                            function updateOverallStatus() {
                                var now = Date.now();
                                var elapsed = (now - globalStartTime) / 1000;
                                var overall = Math.round((currentFileIndex / totalFiles) * 100);

                                if (elapsed > 0 && totalTransferred > 0) {
                                    var speed = (totalTransferred / 1024 / elapsed).toFixed(2);
                                    var estimatedTimeRemaining = '';

                                    if (totalTransferred > 0 && elapsed > 5) {
                                        var bytesPerSecond = totalTransferred / elapsed;
                                        if (bytesPerSecond > 0 && currentFile) {
                                            var totalEstimatedBytes = 0;
                                            for (var i = currentFileIndex; i < totalFiles; i++) {
                                                if (i === currentFileIndex && currentFileSize > 0) {
                                                    totalEstimatedBytes += currentFileSize;
                                                } else {
                                                    totalEstimatedBytes += currentFileSize || 10485760;
                                                }
                                            }

                                            var estimatedSeconds = totalEstimatedBytes / bytesPerSecond;
                                            estimatedTimeRemaining = ' - Est. time remaining: ' + formatTime(estimatedSeconds);
                                        }
                                    }

                                    $('#speed-info').html(
                                        'Overall progress: ' + currentFileIndex + '/' + totalFiles +
                                        ' files (' + overall + '%) at avg ' + speed + ' KB/s' +
                                        estimatedTimeRemaining
                                    );
                                }
                            }

                            function checkTransferLock() {
                                if (transferLock) {
                                    if (!transferLockTimeout) {
                                        transferLockTimeout = setTimeout(function() {
                                            log('Transfer appears stuck, releasing lock...', true);
                                            transferLock = false;
                                            transferLockTimeout = null;
                                            if (currentFile) {
                                                log('Attempting to resume transfer...', true);
                                                uploadFileChunked(currentFile);
                                            }
                                        }, 60000);
                                    }
                                } else {
                                    if (transferLockTimeout) {
                                        clearTimeout(transferLockTimeout);
                                        transferLockTimeout = null;
                                    }
                                }
                            }

                            function uploadFileChunked(file) {
                                if (transferLock) {
                                    log('Transfer already in progress, waiting...', true);
                                    checkTransferLock();
                                    return;
                                }

                                transferLock = true;
                                checkTransferLock();

                                currentFile = file;
                                var chunkSize = chunk_size;
                                var offset = 0;
                                var startTime = Date.now();
                                var retries = 0;
                                var maxRetries = max_retries;
                                var retryDelay = 3;
                                var lastProgressUpdate = Date.now();

                                log('Starting upload: ' + file);

                                function uploadNextChunk() {
                                    if (transferLockTimeout) {
                                        clearTimeout(transferLockTimeout);
                                        transferLockTimeout = null;
                                    }
                                    checkTransferLock();

                                    var now = Date.now();
                                    if (now - lastProgressUpdate > 3000) {
                                        lastProgressUpdate = now;
                                        var timeElapsed = (now - startTime) / 1000;
                                        var speed = timeElapsed > 0 ? (offset / 1024 / timeElapsed).toFixed(2) : '0.00';
                                        $('#speed-info').text('Uploading ' + file + '... at ' + speed + ' KB/s');
                                    }

                                    $.ajax({
                                        url: '<?php echo esc_js($ajax_url); ?>',
                                        method: 'POST',
                                        dataType: 'json',
                                        timeout: 300000,
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
                                            chunk_size: chunkSize,
                                            max_retries: maxRetries
                                        },
                                        success: function(res) {
                                            if (res.success) {
                                                var chunkSize = res.data.new_offset - offset;
                                                offset = res.data.new_offset;
                                                totalTransferred += chunkSize;
                                                currentFileSize = res.data.filesize;

                                                var percent = Math.min(100, Math.round((offset / res.data.filesize) * 100));
                                                var now = Date.now();
                                                var timeElapsed = (now - startTime) / 1000;
                                                var speed = (offset / 1024 / timeElapsed).toFixed(2);
                                                var remainingBytes = res.data.filesize - offset;
                                                var estimatedTimeRemaining = remainingBytes > 0 && offset > 0 ?
                                                    formatTime(remainingBytes / (offset / timeElapsed)) : '';

                                                $('#progress-fill').css('width', percent + '%');
                                                $('#speed-info').html(
                                                    'Uploading ' + file + ': ' + percent + '% at ' + speed + ' KB/s' +
                                                    (estimatedTimeRemaining ? ' - ETA: ' + estimatedTimeRemaining : '') +
                                                    '<br>Transferred: ' + formatFileSize(offset) + ' of ' + formatFileSize(res.data.filesize)
                                                );
                                                lastProgressUpdate = now;

                                                if (offset < res.data.filesize) {
                                                    uploadNextChunk();
                                                } else {
                                                    log(file + ' uploaded successfully (' + formatFileSize(res.data.filesize) + ' in ' +
                                                        timeElapsed.toFixed(1) + 's at ' + speed + ' KB/s)');
                                                    currentFileIndex++;
                                                    updateOverallStatus();
                                                    transferLock = false;

                                                    if (transferLockTimeout) {
                                                        clearTimeout(transferLockTimeout);
                                                        transferLockTimeout = null;
                                                    }

                                                    if (currentFileIndex < totalFiles) {
                                                        setTimeout(function() {
                                                            uploadFileChunked(files[currentFileIndex]);
                                                        }, 500);
                                                    } else {
                                                        var totalTime = (Date.now() - globalStartTime) / 1000;
                                                        log('All files transferred successfully in ' + formatTime(totalTime) + '!');
                                                    }
                                                }
                                                retries = 0;
                                            } else {
                                                log('Error uploading ' + file + ': ' + (res.data?.message || res.data), true);
                                                if (retries < maxRetries) {
                                                    retries++;
                                                    log('Retry ' + retries + '/' + maxRetries + ' in ' + retryDelay + 's...', true);
                                                    setTimeout(uploadNextChunk, retryDelay * 1000);
                                                } else {
                                                    log('Failed to upload ' + file + ' after ' + maxRetries + ' retries. Moving to next file.', true);
                                                    currentFileIndex++;
                                                    updateOverallStatus();
                                                    transferLock = false;

                                                    if (transferLockTimeout) {
                                                        clearTimeout(transferLockTimeout);
                                                        transferLockTimeout = null;
                                                    }

                                                    if (currentFileIndex < totalFiles) {
                                                        setTimeout(function() {
                                                            uploadFileChunked(files[currentFileIndex]);
                                                        }, 1000);
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
                                            else if (textStatus === "timeout") msg += "Request Timeout - File may be too large for single chunk";
                                            else msg += jqXHR.status + " " + errorThrown;

                                            log(msg, true);
                                            if (retries < maxRetries) {
                                                retries++;
                                                if (textStatus === "timeout" && chunkSize > 4194304) {
                                                    chunkSize = Math.floor(chunkSize / 2);
                                                    log('Reducing chunk size to ' + formatFileSize(chunkSize) + ' for better stability', true);
                                                }

                                                log('Retry ' + retries + '/' + maxRetries + ' in ' + retryDelay + 's...', true);
                                                setTimeout(uploadNextChunk, retryDelay * 1000);
                                            } else {
                                                log('Failed to upload ' + file + ' after ' + maxRetries + ' retries. Moving to next file.', true);
                                                currentFileIndex++;
                                                updateOverallStatus();
                                                transferLock = false;

                                                if (transferLockTimeout) {
                                                    clearTimeout(transferLockTimeout);
                                                    transferLockTimeout = null;
                                                }

                                                if (currentFileIndex < totalFiles) {
                                                    setTimeout(function() {
                                                        uploadFileChunked(files[currentFileIndex]);
                                                    }, 1000);
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
                            log('Connection test failed. Transfer aborted.', true);
                        }
                    });
                });

                $('#save-ftp').on('click', function() {
                    var connData = {
                        protocol: $('#protocol').val(),
                        host: $('#host').val().trim(),
                        port: parseInt($('#port').val()),
                        user: $('#user').val().trim(),
                        pass: $('#pass').val().trim(),
                        remote_dir: $('#remote_dir').val().trim(),
                        chunk_size: parseInt($('#chunk_size').val()) || 8388608,
                        max_retries: parseInt($('#max_retries').val()) || 5
                    };

                    $.post('<?php echo esc_js($ajax_url); ?>', {
                        action: 'save_ftp_conn',
                        nonce: '<?php echo esc_js($conn_nonce); ?>',
                        data: connData
                    }, function(res) {
                        if (res.success) {
                            log('Connection settings saved.');
                        } else {
                            log('Error saving settings: ' + res.data, true);
                        }
                    }, 'json');
                });

                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'load_ftp_conn',
                    nonce: '<?php echo esc_js($conn_nonce); ?>'
                }, function(res) {
                    if (res.success && res.data) {
                        $('#protocol').val(res.data.protocol || 'sftp');
                        $('#host').val(res.data.host || '');
                        $('#port').val(res.data.port || (res.data.protocol === 'ftp' ? 21 : 22));
                        $('#user').val(res.data.user || '');
                        $('#pass').val(res.data.pass || '');
                        $('#remote_dir').val(res.data.remote_dir || '');
                        $('#chunk_size').val(res.data.chunk_size || 8388608);
                        $('#max_retries').val(res.data.max_retries || 5);
                        log('Loaded saved connection settings.');
                    }
                }, 'json');

                $('#reset-ftp').on('click', function() {
                    if (confirm('Are you sure you want to reset all connection settings?')) {
                        $.post('<?php echo esc_js($ajax_url); ?>', {
                            action: 'reset_ftp_conn',
                            nonce: '<?php echo esc_js($conn_nonce); ?>'
                        }, function(res) {
                            if (res.success) {
                                $('#protocol').val('sftp');
                                $('#host').val('');
                                $('#port').val(22);
                                $('#user').val('');
                                $('#pass').val('');
                                $('#remote_dir').val('');
                                $('#chunk_size').val(8388608);
                                $('#max_retries').val(5);
                                log('Connection settings reset.');
                            }
                        }, 'json');
                    }
                });
            })(jQuery);
        </script>
<?php
    }

    public function ajax_transfer_file_chunk()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'transfer_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        set_time_limit($this->php_time_limit);
        ini_set('memory_limit', $this->php_memory_limit);

        $protocol = sanitize_text_field($_POST['protocol']);
        $host = sanitize_text_field($_POST['host']);
        $port = (int)($_POST['port'] ?? ($protocol === 'ftp' ? 21 : 22));
        $user = sanitize_text_field($_POST['user']);
        $pass = $_POST['pass'];
        $remote_dir = sanitize_text_field($_POST['remote_dir']);
        $file = sanitize_text_field($_POST['file']);
        $offset = (int)($_POST['offset'] ?? 0);
        $chunk_size = (int)($_POST['chunk_size'] ?? $this->chunk_size);
        $max_retries = (int)($_POST['max_retries'] ?? $this->max_retries);

        $local_path = ABSPATH . $file;
        $remote_path = rtrim($remote_dir, '/') . '/' . $file;

        if (!file_exists($local_path)) {
            wp_send_json_error(['message' => 'Local file not found']);
        }

        $filesize = filesize($local_path);
        $new_offset = min($offset + $chunk_size, $filesize);

        try {
            if ($protocol === 'sftp') {
                $sftp = new SFTP($host, $port);
                $sftp->setTimeout(15);
                $sftp->setKeepAlive(10);

                if (strpos($pass, '-----BEGIN') === 0 || file_exists($pass)) {
                    $key = PublicKeyLoader::load($pass);
                    if (!$sftp->login($user, $key)) {
                        throw new Exception('SFTP login with key failed');
                    }
                } else {
                    if (!$sftp->login($user, $pass)) {
                        throw new Exception('SFTP login failed');
                    }
                }

                $this->verifyServerCompatibility($sftp);
                $this->create_remote_dirs($sftp, dirname($remote_path), 'sftp');
                $offset = $this->validateResumePosition($sftp, $remote_path, $offset);

                $fp = fopen($local_path, 'rb');
                fseek($fp, $offset);

                $bytesToSend = $new_offset - $offset;
                $sent = 0;
                $subChunkSize = 524288; // 512KB sub-chunks
                $retryCount = 0;
                $maxRetries = 3;

                try {
                    while ($sent < $bytesToSend && $retryCount < $maxRetries) {
                        $data = fread($fp, min($subChunkSize, $bytesToSend - $sent));

                        if ($data === false || strlen($data) === 0) {
                            throw new Exception('Failed to read from local file');
                        }

                        $success = $offset === 0 && $sent === 0 ?
                            $sftp->put($remote_path, $data, SFTP::SOURCE_STRING) :
                            $sftp->put($remote_path, $data, SFTP::RESUME | SFTP::SOURCE_STRING);

                        if (!$success) {
                            $stat = $sftp->stat($remote_path);
                            $remoteSize = $stat ? $stat['size'] : 0;
                            $expectedSize = $offset + $sent + strlen($data);

                            if ($remoteSize !== $expectedSize) {
                                throw new Exception("Size mismatch: Local {$expectedSize} vs Remote {$remoteSize}");
                            }

                            $retryCount++;
                            usleep(pow(2, $retryCount) * 100000);
                            continue;
                        }

                        $sent += strlen($data);
                        $retryCount = 0;
                    }

                    if ($sent < $bytesToSend) {
                        throw new Exception("Failed to send full chunk after {$maxRetries} retries");
                    }
                } finally {
                    fclose($fp);
                }

                $remoteSize = $sftp->filesize($remote_path);
                $expectedSize = $new_offset;
                if ($remoteSize !== $expectedSize) {
                    $sftp->delete($remote_path);
                    throw new Exception("Final size mismatch: Expected {$expectedSize}, got {$remoteSize}");
                }
            } elseif ($protocol === 'ftp') {
                $ftp = new FTP($host, $port);
                if (!$ftp->login($user, $pass)) {
                    throw new Exception('FTP login failed');
                }

                $this->create_remote_dirs($ftp, dirname($remote_path), 'ftp');

                $fp = fopen($local_path, 'rb');
                fseek($fp, $offset);

                if (!$ftp->fput($remote_path, $fp, FTP_BINARY, $offset)) {
                    fclose($fp);
                    throw new Exception('Failed to upload chunk via FTP');
                }
                fclose($fp);
            } else {
                throw new Exception('Invalid protocol specified');
            }

            wp_send_json_success([
                'new_offset' => $new_offset,
                'filesize' => $filesize,
                'percent' => round(($new_offset / $filesize) * 100, 2)
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'file' => $file,
                'offset' => $offset
            ]);
        }
    }

    public function ajax_test_ftp_conn()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'transfer_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $protocol = sanitize_text_field($_POST['protocol']);
        $host = sanitize_text_field($_POST['host']);
        $port = (int)($_POST['port'] ?? ($protocol === 'ftp' ? 21 : 22));
        $user = sanitize_text_field($_POST['user']);
        $pass = $_POST['pass'];

        try {
            if ($protocol === 'sftp') {
                $sftp = new SFTP($host, $port);
                $sftp->setTimeout(15);
                if (strpos($pass, '-----BEGIN') === 0 || file_exists($pass)) {
                    $key = PublicKeyLoader::load($pass);
                    if (!$sftp->login($user, $key)) {
                        throw new Exception('SFTP login with key failed');
                    }
                } else {
                    if (!$sftp->login($user, $pass)) {
                        throw new Exception('SFTP login failed');
                    }
                }
            } else if ($protocol === 'ftp') {
                $ftp = new FTP($host, $port);
                if (!$ftp->login($user, $pass)) {
                    throw new Exception('FTP login failed');
                }
            } else {
                throw new Exception('Invalid protocol specified');
            }

            wp_send_json_success(['message' => 'Connection successful']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_save_ftp_conn()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ftp_conn_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $data = [
            'protocol' => sanitize_text_field($_POST['data']['protocol'] ?? 'sftp'),
            'host' => sanitize_text_field($_POST['data']['host'] ?? ''),
            'port' => (int)($_POST['data']['port'] ?? ($_POST['data']['protocol'] === 'ftp' ? 21 : 22)),
            'user' => sanitize_text_field($_POST['data']['user'] ?? ''),
            'pass' => $_POST['data']['pass'] ?? '',
            'remote_dir' => sanitize_text_field($_POST['data']['remote_dir'] ?? ''),
            'chunk_size' => (int)($_POST['data']['chunk_size'] ?? $this->chunk_size),
            'max_retries' => (int)($_POST['data']['max_retries'] ?? $this->max_retries)
        ];

        if (empty($data['host'])) {
            wp_send_json_error(['message' => 'Host is required']);
        }

        if (empty($data['user'])) {
            wp_send_json_error(['message' => 'Username is required']);
        }

        $result = update_option('ftp_sftp_transfer_settings', $data, false);

        if ($result) {
            wp_send_json_success(['message' => 'Settings saved successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to save settings']);
        }
    }

    public function ajax_load_ftp_conn()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ftp_conn_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $settings = get_option('ftp_sftp_transfer_settings', []);
        wp_send_json_success(['data' => $settings]);
    }

    public function ajax_reset_ftp_conn()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ftp_conn_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        delete_option('ftp_sftp_transfer_settings');
        wp_send_json_success();
    }
}

new FTP_SFTP_File_Transfer_Plugin();
