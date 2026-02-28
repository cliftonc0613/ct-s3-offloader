<?php
/**
 * CT S3 Offloader — Uninstall
 *
 * Removes all plugin data when deleted through the WordPress admin.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/* Remove plugin options */
delete_option('s3mo_delete_local');
delete_option('s3mo_path_prefix');

/* Remove offloaded postmeta from all attachments */
global $wpdb;
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_s3mo_offloaded']);
