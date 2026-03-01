<?php
/**
 * Plugin Name: CT S3 Offloader
 * Plugin URI: https://ctwebdesignshop.com
 * Description: Offload WordPress media to Amazon S3 with CloudFront CDN.
 * Version: 1.0.0
 * Author: CT Web Design Shop Inc.
 * Requires PHP: 8.1
 * License: GPL-2.0+
 * Text Domain: ct-s3-offloader
 */

defined('ABSPATH') || exit;

/* ─── Constants ─────────────────────────────────────────────────────── */

define('S3MO_VERSION', '1.0.0');
define('S3MO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('S3MO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('S3MO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/* ─── Autoloader ────────────────────────────────────────────────────── */

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'S3MO_') !== 0) {
        return;
    }

    $file = 'class-' . str_replace('_', '-', strtolower($class)) . '.php';

    $directories = [
        S3MO_PLUGIN_DIR . 'includes/',
        S3MO_PLUGIN_DIR . 'admin/',
    ];

    foreach ($directories as $dir) {
        $path = $dir . $file;
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

/* ─── AWS SDK ───────────────────────────────────────────────────────── */

if (! class_exists('Aws\Sdk')) {
    $sdk_autoloader = S3MO_PLUGIN_DIR . 'aws-sdk/aws-autoloader.php';

    if (! file_exists($sdk_autoloader)) {
        add_action('admin_notices', function (): void {
            echo '<div class="error"><p><strong>CT S3 Offloader:</strong> AWS SDK not found. '
               . 'Please download and extract the SDK into the <code>aws-sdk/</code> directory.</p></div>';
        });
        return;
    }

    require_once $sdk_autoloader;
}

/* ─── WP-CLI Commands ──────────────────────────────────────────────── */

if (defined('WP_CLI') && WP_CLI) {
    $required_cli = ['S3MO_BUCKET', 'S3MO_REGION', 'S3MO_KEY', 'S3MO_SECRET'];
    $missing_cli  = array_filter($required_cli, function ($c) { return ! defined($c); });

    if (empty($missing_cli)) {
        WP_CLI::add_command('ct-s3', new S3MO_CLI_Command(new S3MO_Client()));
    }
}

/* ─── Plugin Initialization ─────────────────────────────────────────── */

add_action('plugins_loaded', function (): void {
    $required = ['S3MO_BUCKET', 'S3MO_REGION', 'S3MO_KEY', 'S3MO_SECRET'];
    $missing  = [];

    foreach ($required as $const) {
        if (! defined($const)) {
            $missing[] = $const;
        }
    }

    /* Admin notices and media column — always active in admin. */
    if (is_admin()) {
        $notices = new S3MO_Admin_Notices();
        $notices->register_hooks();

        $media_column = new S3MO_Media_Column();
        $media_column->register_hooks();
    }

    if (empty($missing)) {
        $client = new S3MO_Client();

        $upload_handler = new S3MO_Upload_Handler($client);
        $upload_handler->register_hooks();

        $url_rewriter = new S3MO_URL_Rewriter($client);
        $url_rewriter->register_hooks();

        $delete_handler = new S3MO_Delete_Handler($client);
        $delete_handler->register_hooks();

        if (is_admin()) {
            $settings = new S3MO_Settings_Page($client);
            $settings->register_hooks();
        }
    } elseif (is_admin()) {
        $settings = new S3MO_Settings_Page(null);
        $settings->register_hooks();
    }
}, 10);

/* ─── Activation / Deactivation ─────────────────────────────────────── */

register_activation_hook(__FILE__, function (): void {
    add_option('s3mo_path_prefix', 'wp-content/uploads');
    add_option('s3mo_delete_local', false);
});

register_deactivation_hook(__FILE__, function (): void {
    delete_transient('s3mo_connection_status');
});
