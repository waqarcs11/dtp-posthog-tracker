<?php
/**
 * Plugin Name: DTP PostHog Tracker
 * Description: Sends Ninja Forms submissions to PostHog with all field properties, form details, and site info.
 * Version: 1.0.0
 * Author: DataTools Pro
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

class DTP_PostHog_Tracker {

    private $api_key;
    private $posthog_url;
    private $event_name;

    public function __construct() {
        // Get settings from options (no defaults - customer must configure)
        $this->api_key = get_option('dtp_posthog_api_key', '');
        $this->posthog_url = get_option('dtp_posthog_url', '');
        $this->event_name = get_option('dtp_posthog_event_name', 'Fundacann Form Submitted');

        // Add admin menu
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Add settings link to plugin listing page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Show admin notice if settings are not configured
        add_action('admin_notices', [$this, 'admin_notice_missing_settings']);

        // Inject PostHog JS snippet into <head>
        add_action('wp_head', [$this, 'inject_posthog_snippet']);

        // Enqueue client-side property collector
        add_action('wp_enqueue_scripts', [$this, 'enqueue_client_script']);

        // Enqueue Breakdance form tracker
        add_action('wp_enqueue_scripts', [$this, 'enqueue_breakdance_script']);

        // Only add the hook if Ninja Forms is active
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        if (class_exists('Ninja_Forms')) {
            add_action('ninja_forms_after_submission', [$this, 'send_form_to_posthog']);
        } else {
            error_log('⚠️ DTP PostHog Tracker: Ninja Forms not active, skipping tracking.');
        }
    }

    /**
     * Inject PostHog JS snippet into <head> on frontend
     */
    public function inject_posthog_snippet() {
        if (empty($this->api_key) || empty($this->posthog_url)) {
            return;
        }

        // Derive base host from the stored capture URL (e.g. https://app.posthog.com/capture/ → https://app.posthog.com)
        $parsed   = parse_url($this->posthog_url);
        $api_host = $parsed['scheme'] . '://' . $parsed['host'];
        ?>
        <script>
            if (!window.posthog || !window.posthog.__loaded) {
                !function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.async=!0,p.src=s.api_host+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+" (stub)"},o="capture identify alias people.set people.set_once set_config register register_once unregister opt_out_capturing has_opted_out_capturing opt_in_capturing reset isFeatureEnabled onFeatureFlags getFeatureFlag getFeatureFlagPayload reloadFeatureFlags group updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures getActiveMatchingSurveys getSurveys onSessionId".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||(window.posthog=[]));
                posthog.init('<?php echo esc_js($this->api_key); ?>', {api_host: '<?php echo esc_js($api_host); ?>'});
            }
        </script>
        <?php
    }

    /**
     * Enqueue client-side property collector on frontend
     */
    public function enqueue_client_script() {
        wp_enqueue_script(
            'dtp-posthog-client',
            plugin_dir_url(__FILE__) . 'js/dtp-posthog-client.js',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'js/dtp-posthog-client.js'),
            true
        );
    }

    /**
     * Read correlation_id from cookie set by client-side JS on submit click
     */
    private function get_client_properties() {
        if (empty($_COOKIE['dtp_correlation_id'])) {
            return [];
        }

        $correlation_id = sanitize_text_field(wp_unslash($_COOKIE['dtp_correlation_id']));

        // Validate it looks like a UUID before using it
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $correlation_id)) {
            return [];
        }

        return ['correlation_id' => $correlation_id];
    }

    /**
     * Enqueue Breakdance form tracker script on frontend
     */
    public function enqueue_breakdance_script() {
        wp_enqueue_script(
            'dtp-breakdance-forms',
            plugin_dir_url(__FILE__) . 'js/dtp-breakdance-forms.js',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'js/dtp-breakdance-forms.js'),
            true
        );

        $parsed   = parse_url($this->posthog_url);
        $api_host = (!empty($parsed['scheme']) && !empty($parsed['host']))
            ? $parsed['scheme'] . '://' . $parsed['host']
            : '';

        wp_localize_script('dtp-breakdance-forms', 'dtpBreakdanceConfig', [
            'eventName' => !empty($this->event_name) ? $this->event_name : 'Fundacann Form Submitted',
            'debug'     => (defined('WP_DEBUG') && WP_DEBUG) ? true : false,
            'apiKey'    => $this->api_key,
            'apiHost'   => $api_host,
        ]);
    }

    /**
     * Add settings page to WordPress admin menu
     */
    public function add_settings_page() {
        add_options_page(
            'PostHog Tracker Settings',      // Page title
            'PostHog Tracker',                // Menu title
            'manage_options',                  // Capability
            'dtp-posthog-settings',           // Menu slug
            [$this, 'render_settings_page']   // Callback function
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('dtp_posthog_settings_group', 'dtp_posthog_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);

        register_setting('dtp_posthog_settings_group', 'dtp_posthog_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);

        register_setting('dtp_posthog_settings_group', 'dtp_posthog_event_name', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Fundacann Form Submitted'
        ]);

        add_settings_section(
            'dtp_posthog_main_section',
            'PostHog Configuration',
            [$this, 'settings_section_callback'],
            'dtp-posthog-settings'
        );

        add_settings_field(
            'dtp_posthog_api_key',
            'PostHog API Key',
            [$this, 'api_key_field_callback'],
            'dtp-posthog-settings',
            'dtp_posthog_main_section'
        );

        add_settings_field(
            'dtp_posthog_url',
            'PostHog URL',
            [$this, 'url_field_callback'],
            'dtp-posthog-settings',
            'dtp_posthog_main_section'
        );

        add_settings_field(
            'dtp_posthog_event_name',
            'Event Name',
            [$this, 'event_name_field_callback'],
            'dtp-posthog-settings',
            'dtp_posthog_main_section'
        );
    }

    /**
     * Settings section description
     */
    public function settings_section_callback() {
        echo '<p>Configure your PostHog tracking settings below:</p>';
    }

    /**
     * API Key field callback
     */
    public function api_key_field_callback() {
        $api_key = get_option('dtp_posthog_api_key', '');
        echo '<input type="text" name="dtp_posthog_api_key" value="' . esc_attr($api_key) . '" class="regular-text" placeholder="phc_xxxxxxxxxxxxxx" required />';
        echo '<p class="description">Enter your PostHog project API key (starts with "phc_")</p>';
    }

    /**
     * URL field callback
     */
    public function url_field_callback() {
        $url = get_option('dtp_posthog_url', '');
        echo '<input type="url" name="dtp_posthog_url" value="' . esc_attr($url) . '" class="regular-text" placeholder="https://app.posthog.com/capture/" required />';
        echo '<p class="description">Enter your PostHog capture URL (e.g., https://app.posthog.com/capture/)</p>';
    }

    /**
     * Event Name field callback
     */
    public function event_name_field_callback() {
        $event_name = get_option('dtp_posthog_event_name', 'Fundacann Form Submitted');
        echo '<input type="text" name="dtp_posthog_event_name" value="' . esc_attr($event_name) . '" class="regular-text" placeholder="Fundacann Form Submitted" />';
        echo '<p class="description">Enter the event name to send to PostHog (default: "Fundacann Form Submitted")</p>';
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if settings were updated
        if (isset($_GET['settings-updated'])) {
            add_settings_error('dtp_posthog_messages', 'dtp_posthog_message', 'Settings Saved', 'updated');
        }

        settings_errors('dtp_posthog_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('dtp_posthog_settings_group');
                do_settings_sections('dtp-posthog-settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add settings link to plugin listing page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=dtp-posthog-settings">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Show admin notice if PostHog settings are not configured
     */
    public function admin_notice_missing_settings() {
        // Only show on admin pages
        if (!current_user_can('manage_options')) {
            return;
        }

        // Don't show on the settings page itself
        $screen = get_current_screen();
        if ($screen && $screen->id === 'settings_page_dtp-posthog-settings') {
            return;
        }

        // Check if settings are missing
        $api_key = get_option('dtp_posthog_api_key', '');
        $url = get_option('dtp_posthog_url', '');

        if (empty($api_key) || empty($url)) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>PostHog Tracker:</strong> Please configure your PostHog API Key and URL to start tracking form submissions.
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=dtp-posthog-settings')); ?>">Configure Settings</a>
                </p>
            </div>
            <?php
        }
    }

    public function send_form_to_posthog($form_data) {
        try {
            error_log('🚀 DTP PostHog Tracker: Submission triggered.');

            // Extract form details
            $form_id       = $form_data['form_id'] ?? null;
            $form_title    = $form_data['settings']['title'] ?? 'Unknown Form';
            $submission_id = $form_data['actions']['save']['sub_id'] ?? uniqid();
            $site_id       = $_SERVER['SERVER_NAME'] ?? 'Unknown Site';
            $distinct_id   = is_user_logged_in() ? get_current_user_id() : 'submission_' . $submission_id;

            // Extract all field values
            $field_properties = [];
            if (!empty($form_data['fields']) && is_array($form_data['fields'])) {
                foreach ($form_data['fields'] as $field) {
                    $key   = $field['key'] ?? $field['label'];
                    $value = $field['value'] ?? null;

                    if ($key && $value !== null) {
                        // Clean up field name
                        $normalized_key = preg_replace('/_\d+$/', '', $key); // remove numeric suffixes
                        $normalized_key = preg_replace('/\s+/', '_', strtolower($normalized_key));
                        $normalized_key = preg_replace('/[^a-z0-9_]/', '', $normalized_key);
                        $field_properties[$normalized_key] = $value;
                    }
                }
            }

            // Get client-side properties (browser, device, UTM, etc.)
            $client_properties = $this->get_client_properties();

            // Merge: client props first, then base props (base props take priority)
            $properties = array_merge($client_properties, [
                'FormSubmissionId' => $submission_id,
                'FormId'           => $form_id,
                'FormType'         => $form_title,
                'SiteId'           => $site_id,
            ], $field_properties);

            // Build payload
            $payload = [
                'api_key'     => $this->api_key,
                'distinct_id' => $distinct_id,
                'event'       => !empty($this->event_name) ? $this->event_name : 'Fundacann Form Submitted',
                'properties'  => $properties,
            ];

            $this->send_to_posthog($payload);

        } catch (Exception $e) {
            error_log('❌ DTP PostHog Tracker Exception: ' . $e->getMessage());
        }
    }

    private function send_to_posthog($payload) {
        if (empty($this->api_key)) {
            error_log('⚠️ DTP PostHog Tracker: Missing API Key. Please configure in Settings > PostHog Tracker.');
            return;
        }

        if (empty($this->posthog_url)) {
            error_log('⚠️ DTP PostHog Tracker: Missing PostHog URL. Please configure in Settings > PostHog Tracker.');
            return;
        }

        $response = wp_remote_post($this->posthog_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ DTP PostHog Tracker Error: ' . $response->get_error_message());
        } else {
            error_log('✅ DTP PostHog Tracker Response: ' . wp_remote_retrieve_body($response));
        }
    }
}

// Initialize plugin
new DTP_PostHog_Tracker();
