<?php
/**
 * Uninstall handler for Quick Send - FTP/SFTP File Transfer
 * 
 * @package QuickSendFTP
 */

// Exit if accessed directly or not through WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN') || !current_user_can('delete_plugins')) {
    exit;
}

// Prevent any timeouts during cleanup
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

// Define plugin-specific cleanup functions
function quicksend_ftp_remove_plugin_data() {
    global $wpdb;

    // Delete all plugin options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ftp_sftp_%'");
    
    // Delete transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_quicksend_ftp_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_quicksend_ftp_%'");
    
    // Delete log file if it exists
    $log_file = WP_CONTENT_DIR . '/ftp-sftp-transfer.log';
    if (file_exists($log_file)) {
        @unlink($log_file);
    }
    
    // Clear any scheduled events
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('quicksend_ftp_daily_cleanup');
    }
    
}

// Check if the "Delete Data on Uninstall" option was set
$delete_data = get_option('ftp_sftp_transfer_clear_on_uninstall', false);

if ($delete_data) {
    quicksend_ftp_remove_plugin_data();
    
    // For multisite installations
    if (is_multisite()) {
        $sites = get_sites(array('fields' => 'ids'));
        
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            quicksend_ftp_remove_plugin_data();
            restore_current_blog();
        }
    }
}
