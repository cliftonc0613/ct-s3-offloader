<?php
/**
 * S3MO_Admin_Notices — Centralized admin warning notices for CT S3 Offloader.
 *
 * Displays dismissible notices for missing credentials, failed connection tests,
 * and missing CDN URL configuration. Notices reappear on each page load.
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Admin_Notices {

    /**
     * Register the admin_notices hook.
     */
    public function register_hooks(): void {
        add_action('admin_notices', [$this, 'render_notices']);
    }

    /**
     * Render all applicable admin notices.
     */
    public function render_notices(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->notice_missing_credentials();
        $this->notice_failed_connection();
        $this->notice_missing_cdn_url();
    }

    /**
     * Display warning when required credential constants are missing.
     */
    private function notice_missing_credentials(): void {
        $required = ['S3MO_BUCKET', 'S3MO_REGION', 'S3MO_KEY', 'S3MO_SECRET'];
        $missing  = [];

        foreach ($required as $const) {
            if (! defined($const)) {
                $missing[] = $const;
            }
        }

        if (empty($missing)) {
            return;
        }

        $list = implode(', ', array_map(function (string $c): string {
            return '<code>' . esc_html($c) . '</code>';
        }, $missing));

        echo '<div class="notice notice-warning is-dismissible"><p>'
           . '<strong>CT S3 Offloader:</strong> '
           . 'Missing credential constants in <code>wp-config.php</code>: '
           . $list
           . '</p></div>';
    }

    /**
     * Display error when the S3 connection test has failed.
     */
    private function notice_failed_connection(): void {
        $status = get_transient('s3mo_connection_status');

        // Only show if transient exists and indicates failure.
        if ($status === false || ! empty($status['success'])) {
            return;
        }

        echo '<div class="notice notice-error is-dismissible"><p>'
           . '<strong>CT S3 Offloader:</strong> '
           . 'S3 connection test failed. Check your credentials and bucket configuration.'
           . '</p></div>';
    }

    /**
     * Display info notice when CloudFront CDN URL is not configured.
     */
    private function notice_missing_cdn_url(): void {
        if (defined('S3MO_CDN_URL') && ! empty(S3MO_CDN_URL)) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>'
           . '<strong>CT S3 Offloader:</strong> '
           . 'CloudFront URL is not configured. Files will use direct S3 URLs. '
           . 'Define <code>S3MO_CDN_URL</code> in <code>wp-config.php</code> for CDN delivery.'
           . '</p></div>';
    }
}
