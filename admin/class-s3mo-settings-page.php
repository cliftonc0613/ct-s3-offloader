<?php
/**
 * S3MO_Settings_Page — Admin settings page for CT S3 Offloader.
 *
 * Registers the settings page under Media menu, renders credential display,
 * connection test button, and saveable options (path prefix, delete local).
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

class S3MO_Settings_Page {

    private ?S3MO_Client $client;
    private string $hook_suffix = '';

    /**
     * @param S3MO_Client|null $client Null when credentials are missing.
     */
    public function __construct(?S3MO_Client $client) {
        $this->client = $client;
    }

    /**
     * Register all admin hooks.
     */
    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_s3mo_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_s3mo_refresh_stats', [$this, 'ajax_refresh_stats']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add settings page under Media menu.
     */
    public function add_menu(): void {
        $this->hook_suffix = (string) add_media_page(
            'CT S3 Offloader Settings',
            'S3 Offloader',
            'manage_options',
            'ct-s3-offloader',
            [$this, 'render_page']
        );
    }

    /**
     * Register plugin settings via Settings API.
     */
    public function register_settings(): void {
        register_setting('s3mo_settings', 's3mo_path_prefix', [
            'sanitize_callback' => [$this, 'sanitize_path_prefix'],
            'type'              => 'string',
            'default'           => 'wp-content/uploads',
        ]);

        register_setting('s3mo_settings', 's3mo_delete_local', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'type'              => 'boolean',
            'default'           => false,
        ]);

        register_setting('s3mo_settings', 's3mo_delete_s3_on_uninstall', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'type'              => 'boolean',
            'default'           => false,
        ]);
    }

    /**
     * Sanitize the path prefix option.
     *
     * @param mixed $value Raw input value.
     * @return string Sanitized path prefix.
     */
    public function sanitize_path_prefix($value): string {
        $value = sanitize_text_field((string) $value);
        $value = trim($value, '/');

        if (empty($value)) {
            add_settings_error(
                's3mo_path_prefix',
                'empty',
                'Path prefix cannot be empty.'
            );
            return get_option('s3mo_path_prefix', 'wp-content/uploads');
        }

        return $value;
    }

    /**
     * AJAX handler for connection test.
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('s3mo_test_nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        if (! $this->client) {
            wp_send_json_error(['message' => 'AWS credentials not configured in wp-config.php']);
        }

        $result = $this->client->test_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for stats refresh.
     */
    public function ajax_refresh_stats(): void {
        check_ajax_referer('s3mo_test_nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $stats = S3MO_Stats::refresh();

        $formatted = [
            'total_files'    => number_format_i18n($stats['total_files']),
            'total_size'     => size_format($stats['total_size']),
            'pending'        => number_format_i18n($stats['pending']),
            'last_offloaded' => $stats['last_offloaded']
                ? human_time_diff(strtotime($stats['last_offloaded']), current_time('timestamp')) . ' ago'
                : 'Never',
        ];

        wp_send_json_success($formatted);
    }

    /**
     * Enqueue admin assets only on our settings page.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook): void {
        if ($hook !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_style(
            's3mo-admin',
            S3MO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            S3MO_VERSION
        );

        wp_enqueue_script(
            's3mo-admin',
            S3MO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            S3MO_VERSION,
            true
        );

        wp_localize_script('s3mo-admin', 's3moAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('s3mo_test_nonce'),
        ]);
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void {
        $stats = S3MO_Stats::get_cached();
        $formatted_size = size_format($stats['total_size']);
        $formatted_last = $stats['last_offloaded']
            ? human_time_diff(strtotime($stats['last_offloaded']), current_time('timestamp')) . ' ago'
            : 'Never';
        ?>
        <div class="wrap">
            <h1>CT S3 Offloader Settings</h1>

            <div class="s3mo-stats-dashboard">
                <h2>Storage Statistics</h2>
                <div class="s3mo-stats-grid">
                    <div class="s3mo-stat-card">
                        <span class="s3mo-stat-value" id="s3mo-stat-total-files"><?php echo esc_html(number_format_i18n($stats['total_files'])); ?></span>
                        <span class="s3mo-stat-label">Files on S3</span>
                    </div>
                    <div class="s3mo-stat-card">
                        <span class="s3mo-stat-value" id="s3mo-stat-total-size"><?php echo esc_html($formatted_size); ?></span>
                        <span class="s3mo-stat-label">Total Size</span>
                    </div>
                    <div class="s3mo-stat-card">
                        <span class="s3mo-stat-value" id="s3mo-stat-pending"><?php echo esc_html(number_format_i18n($stats['pending'])); ?></span>
                        <span class="s3mo-stat-label">Pending</span>
                    </div>
                    <div class="s3mo-stat-card">
                        <span class="s3mo-stat-value" id="s3mo-stat-last-offloaded"><?php echo esc_html($formatted_last); ?></span>
                        <span class="s3mo-stat-label">Last Offloaded</span>
                    </div>
                </div>
                <p>
                    <button type="button" class="button button-secondary" id="s3mo-refresh-stats">Refresh Stats</button>
                </p>
            </div>

            <h2>AWS Credentials</h2>
            <p class="description">
                Credentials are read from <code>wp-config.php</code> constants.
                <a href="https://developer.wordpress.org/advanced-administration/wordpress/wp-config/" target="_blank">Learn more</a>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">S3MO_BUCKET</th>
                    <td>
                        <?php if (defined('S3MO_BUCKET')) : ?>
                            <span class="s3mo-credential-value"><?php echo esc_html(S3MO_BUCKET); ?></span>
                        <?php else : ?>
                            <span class="s3mo-not-defined">Not defined</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">S3MO_REGION</th>
                    <td>
                        <?php if (defined('S3MO_REGION')) : ?>
                            <span class="s3mo-credential-value"><?php echo esc_html(S3MO_REGION); ?></span>
                        <?php else : ?>
                            <span class="s3mo-not-defined">Not defined</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">S3MO_KEY</th>
                    <td>
                        <?php if (defined('S3MO_KEY')) : ?>
                            <span class="s3mo-credential-value"><?php echo '••••' . esc_html(substr(S3MO_KEY, -4)); ?></span>
                        <?php else : ?>
                            <span class="s3mo-not-defined">Not defined</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">S3MO_SECRET</th>
                    <td>
                        <?php if (defined('S3MO_SECRET')) : ?>
                            <span class="s3mo-credential-value">••••••••</span>
                        <?php else : ?>
                            <span class="s3mo-not-defined">Not defined</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">S3MO_CDN_URL</th>
                    <td>
                        <?php if (defined('S3MO_CDN_URL') && ! empty(S3MO_CDN_URL)) : ?>
                            <span class="s3mo-credential-value"><?php echo esc_html(S3MO_CDN_URL); ?></span>
                        <?php else : ?>
                            <span class="s3mo-not-defined">Not defined (optional — will use direct S3 URL)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>Connection Test</h2>
            <p>
                <button type="button" class="button button-secondary" id="s3mo-test-connection">Test Connection</button>
            </p>
            <div id="s3mo-test-result"></div>

            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields('s3mo_settings'); ?>
                <?php settings_errors(); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="s3mo_path_prefix">S3 Path Prefix</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="s3mo_path_prefix"
                                   name="s3mo_path_prefix"
                                   value="<?php echo esc_attr(get_option('s3mo_path_prefix', 'wp-content/uploads')); ?>"
                                   class="regular-text" />
                            <p class="description">
                                Prefix added to S3 object keys (e.g., wp-content/uploads)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Delete Local Files</th>
                        <td>
                            <label for="s3mo_delete_local">
                                <input type="checkbox"
                                       id="s3mo_delete_local"
                                       name="s3mo_delete_local"
                                       value="1"
                                       <?php checked(get_option('s3mo_delete_local', false)); ?> />
                                Remove local file after successful S3 upload (saves disk space)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Delete S3 Files on Uninstall</th>
                        <td>
                            <label for="s3mo_delete_s3_on_uninstall">
                                <input type="checkbox"
                                       id="s3mo_delete_s3_on_uninstall"
                                       name="s3mo_delete_s3_on_uninstall"
                                       value="1"
                                       <?php checked(get_option('s3mo_delete_s3_on_uninstall', false)); ?> />
                                Delete all S3 objects when the plugin is uninstalled
                            </label>
                            <p class="description" style="color: #d63638;">
                                <strong>Warning:</strong> This will permanently delete all offloaded media files from S3 when the plugin is deleted.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
