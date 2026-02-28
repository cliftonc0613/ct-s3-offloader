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
     * Enqueue admin assets only on our settings page.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook): void {
        if ($hook !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_style('wp-admin');

        wp_add_inline_style('wp-admin', '
            .s3mo-not-defined { color: #d63638; font-weight: 600; }
            .s3mo-credential-value { font-family: monospace; }
        ');

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
        ?>
        <div class="wrap">
            <h1>CT S3 Offloader Settings</h1>

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
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
