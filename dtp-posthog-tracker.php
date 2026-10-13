<?php
/**
 * Plugin Name: DataTools Pro PostHog Tracker
 * Description: Sends Contact Form 7, WPForms, Gravity Forms, Ninja Forms and Breakdance form submissions to PostHog with all field properties, form details, and site info.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: DataTools Pro
 * Author URI: https://datatoolspro.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: datatools-pro-posthog-tracker
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('DTP_POSTHOG_TRACKER_VERSION', '1.1.0');

class DTP_PostHog_Tracker {

    private $api_key;
    private $posthog_url;
    private $event_name;
    private $ui_host;
    private $defaults;
    private $person_profiles;

    public function __construct() {
        // Get settings from options (no defaults - customer must configure)
        $this->api_key = get_option('dtp_posthog_api_key', '');
        $this->posthog_url = get_option('dtp_posthog_url', '');
        $this->event_name = get_option('dtp_posthog_event_name', 'Form Submitted');

        // Browser-side init() options 
        $this->ui_host         = get_option('dtp_posthog_ui_host', '');
        $this->defaults        = get_option('dtp_posthog_defaults', '');
        $this->person_profiles = get_option('dtp_posthog_person_profiles', 'identified_only');

        // Add admin menu
        add_action('admin_menu', [$this, 'add_settings_page']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Add settings link to plugin listing page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Show admin notice if settings are not configured
        add_action('admin_notices', [$this, 'admin_notice_missing_settings']);

        // Inject PostHog JS snippet into <head>
        add_action('wp_enqueue_scripts', [$this, 'inject_posthog_snippet']);

        // Enqueue client-side property collector
        add_action('wp_enqueue_scripts', [$this, 'enqueue_client_script']);

        // Enqueue Breakdance form tracker
        add_action('wp_enqueue_scripts', [$this, 'enqueue_breakdance_script']);

        // Only add the hook if Ninja Forms is active
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Debug logging, only active when WP_DEBUG_LOG is enabled.
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public function init() {
        // Each hook only fires if the corresponding form plugin is active.
        add_action('ninja_forms_after_submission', [$this, 'send_form_to_posthog']);
        add_action('wpcf7_mail_sent', [$this, 'handle_cf7_submission']);
        add_action('wpforms_process_complete', [$this, 'handle_wpforms_submission'], 10, 4);
        add_action('gform_after_submission', [$this, 'handle_gravity_submission'], 10, 2);
    }

    /**
     * Enqueue the PostHog JS loader snippet and init call on the frontend
     */
    public function inject_posthog_snippet() {
        if (empty($this->api_key) || empty($this->posthog_url)) {
            return;
        }

        // Derive base host from the stored capture URL (e.g. https://app.posthog.com/capture/ → https://app.posthog.com)
        $parsed   = wp_parse_url($this->posthog_url);
        $api_host = $parsed['scheme'] . '://' . $parsed['host'];

        // Build the init() options object. api_host is the saved PostHog URL's
        // host (or your reverse proxy); the rest are configurable
        // under Settings > PostHog Tracker. Empty values are omitted so PostHog
        // falls back to its own defaults.
        $init_options = ['api_host' => $api_host];
        if (!empty($this->ui_host)) {
            $init_options['ui_host'] = $this->ui_host;
        }
        if (!empty($this->defaults)) {
            $init_options['defaults'] = $this->defaults;
        }
        if (!empty($this->person_profiles)) {
            $init_options['person_profiles'] = $this->person_profiles;
        }

        wp_enqueue_script(
            'dtp-posthog-snippet',
            plugin_dir_url(__FILE__) . 'js/dtp-posthog-snippet.js',
            [],
            DTP_POSTHOG_TRACKER_VERSION,
            false
        );
        wp_add_inline_script(
            'dtp-posthog-snippet',
            'posthog.init(' . wp_json_encode($this->api_key) . ', ' . wp_json_encode($init_options) . ');'
        );
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
     * Find the first field value that looks like an email address.
     */
    private function find_email_in_properties($properties) {
        foreach ($properties as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Read the anonymous PostHog distinct_id from the posthog.js cookie.
     */
    private function get_posthog_anon_distinct_id() {
        if (empty($this->api_key)) {
            return null;
        }

        $cookie_name = 'ph_' . $this->api_key . '_posthog';
        if (empty($_COOKIE[$cookie_name])) {
            return null;
        }

        $data = json_decode(wp_unslash($_COOKIE[$cookie_name]), true);
        if (!is_array($data) || empty($data['distinct_id'])) {
            return null;
        }

        return sanitize_text_field($data['distinct_id']);
    }

    /**
     * Link the visitor's PostHog profile to a known person. If $anon_distinct_id
     * is omitted, only the person's $set properties are updated (used when the
     * browser was previously identified as a *different* email — switching the
     * active identity without merging two already-identified people together).
     */
    private function send_identify_event($email, $field_properties, $anon_distinct_id = null) {
        $set_properties = ['email' => $email];

        foreach ($field_properties as $key => $value) {
            if (preg_match('/name|phone|mobile|tel/i', $key)) {
                $set_properties[$key] = $value;
            }
        }

        $properties = ['$set' => $set_properties];
        if ($anon_distinct_id) {
            $properties['$anon_distinct_id'] = $anon_distinct_id;
        }

        $this->send_to_posthog([
            'api_key'     => $this->api_key,
            'event'       => '$identify',
            'distinct_id' => $email,
            'properties'  => $properties,
        ]);
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

        $parsed   = wp_parse_url($this->posthog_url);
        $api_host = (!empty($parsed['scheme']) && !empty($parsed['host']))
            ? $parsed['scheme'] . '://' . $parsed['host']
            : '';

        wp_localize_script('dtp-breakdance-forms', 'dtpBreakdanceConfig', [
            'eventName' => !empty($this->event_name) ? $this->event_name : 'Form Submitted',
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
            'DataTools Pro PostHog Tracker Settings', // Page title
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
            'default' => 'Form Submitted'
        ]);

        register_setting('dtp_posthog_settings_group', 'dtp_posthog_ui_host', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);

        register_setting('dtp_posthog_settings_group', 'dtp_posthog_defaults', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);

        register_setting('dtp_posthog_settings_group', 'dtp_posthog_person_profiles', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'identified_only'
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

        add_settings_field(
            'dtp_posthog_ui_host',
            'PostHog UI Host',
            [$this, 'ui_host_field_callback'],
            'dtp-posthog-settings',
            'dtp_posthog_main_section'
        );

        add_settings_field(
            'dtp_posthog_defaults',
            'Config Defaults Version',
            [$this, 'defaults_field_callback'],
            'dtp-posthog-settings',
            'dtp_posthog_main_section'
        );

        add_settings_field(
            'dtp_posthog_person_profiles',
            'Person Profiles',
            [$this, 'person_profiles_field_callback'],
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
        echo '<input type="url" name="dtp_posthog_url" value="' . esc_attr($url) . '" class="regular-text" placeholder="https://analytics.example.com" required />';
        echo '<p class="description">Your PostHog capture URL. The browser <code>api_host</code> is derived from this URL\'s host, so point it at your reverse proxy when using one (e.g. https://analytics.example.com/capture/). Without a proxy, use https://app.posthog.com/capture/.</p>';
    }

    /**
     * Event Name field callback
     */
    public function event_name_field_callback() {
        $event_name = get_option('dtp_posthog_event_name', 'Form Submitted');
        echo '<input type="text" name="dtp_posthog_event_name" value="' . esc_attr($event_name) . '" class="regular-text" placeholder="Form Submitted" />';
        echo '<p class="description">Enter the event name to send to PostHog (default: "Form Submitted")</p>';
    }

    /**
     * UI Host field callback
     */
    public function ui_host_field_callback() {
        $ui_host = get_option('dtp_posthog_ui_host', '');
        echo '<input type="url" name="dtp_posthog_ui_host" value="' . esc_attr($ui_host) . '" class="regular-text" placeholder="https://us.posthog.com" />';
        echo '<p class="description">Only needed when the PostHog URL above is a reverse proxy (e.g. https://analytics.example.com). Set this to your real PostHog host so dashboard links resolve correctly &mdash; US: https://us.posthog.com, EU: https://eu.posthog.com. Leave blank if not using a proxy.</p>';
    }

    /**
     * Config "defaults" version field callback
     */
    public function defaults_field_callback() {
        $defaults = get_option('dtp_posthog_defaults', '');
        echo '<input type="text" name="dtp_posthog_defaults" value="' . esc_attr($defaults) . '" class="regular-text" placeholder="2026-05-30" />';
        echo '<p class="description">PostHog config "defaults" version, e.g. 2026-05-30. Pins default behavior to a dated baseline. Leave blank to omit the option.</p>';
    }

    /**
     * Person Profiles field callback
     */
    public function person_profiles_field_callback() {
        $person_profiles = get_option('dtp_posthog_person_profiles', 'identified_only');
        ?>
        <select name="dtp_posthog_person_profiles">
            <option value="identified_only" <?php selected($person_profiles, 'identified_only'); ?>>identified_only</option>
            <option value="always" <?php selected($person_profiles, 'always'); ?>>always</option>
        </select>
        <p class="description">"identified_only" creates person profiles only for identified users (recommended). "always" also profiles anonymous visitors.</p>
        <?php
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
                    <strong>DataTools Pro PostHog Tracker:</strong> Please configure your PostHog API Key and URL to start tracking form submissions.
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=dtp-posthog-settings')); ?>">Configure Settings</a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Ninja Forms handler.
     */
    public function send_form_to_posthog($form_data) {
        $fields = [];
        if (!empty($form_data['fields']) && is_array($form_data['fields'])) {
            foreach ($form_data['fields'] as $field) {
                $fields[] = [
                    'key'   => $field['key'] ?? ($field['label'] ?? ''),
                    'value' => $field['value'] ?? null,
                ];
            }
        }

        $this->track_submission(
            $form_data['form_id'] ?? null,
            $form_data['settings']['title'] ?? 'Unknown Form',
            $form_data['actions']['save']['sub_id'] ?? uniqid(),
            $fields
        );
    }

    /**
     * Contact Form 7 handler (fires after the mail was sent successfully).
     */
    public function handle_cf7_submission($contact_form) {
        if (!class_exists('WPCF7_Submission')) {
            return;
        }
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $fields = [];
        foreach ((array) $submission->get_posted_data() as $key => $value) {
            if (strpos($key, '_') === 0) {
                continue; // internal CF7 fields (_wpcf7, _wpcf7_unit_tag, ...)
            }
            $fields[] = ['key' => $key, 'value' => $value];
        }

        $this->track_submission(
            $contact_form->id(),
            $contact_form->title(),
            uniqid(),
            $fields
        );
    }

    /**
     * WPForms handler.
     */
    public function handle_wpforms_submission($wpforms_fields, $entry, $form_data, $entry_id) {
        $skip_types = ['pagebreak', 'divider', 'html', 'content', 'captcha', 'hidden-honeypot'];

        $fields = [];
        foreach ((array) $wpforms_fields as $field) {
            if (in_array($field['type'] ?? '', $skip_types, true)) {
                continue;
            }
            $fields[] = [
                'key'   => $field['name'] ?? '',
                'value' => $field['value'] ?? null,
            ];
        }

        $this->track_submission(
            $form_data['id'] ?? null,
            $form_data['settings']['form_title'] ?? 'Unknown Form',
            $entry_id ? $entry_id : uniqid(),
            $fields
        );
    }

    /**
     * Gravity Forms handler.
     */
    public function handle_gravity_submission($entry, $form) {
        $skip_types = ['page', 'section', 'html', 'captcha'];

        $fields = [];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (in_array($field->type, $skip_types, true)) {
                continue;
            }

            if (is_array($field->inputs) && !empty($field->inputs)) {
                // Multi-input fields (name, address, checkboxes): join the parts.
                $parts = [];
                foreach ($field->inputs as $input) {
                    $part = $entry[(string) $input['id']] ?? '';
                    if ($part !== '' && $part !== null) {
                        $parts[] = $part;
                    }
                }
                $value = implode(', ', $parts);
            } else {
                $value = $entry[(string) $field->id] ?? '';
            }

            $fields[] = ['key' => $field->label, 'value' => $value];
        }

        $this->track_submission(
            $form['id'] ?? null,
            $form['title'] ?? 'Unknown Form',
            $entry['id'] ?? uniqid(),
            $fields
        );
    }

    /**
     * Shared pipeline: normalise fields, identify the visitor by email, and
     * send the form event to PostHog.
     *
     * @param mixed  $form_id
     * @param string $form_title
     * @param mixed  $submission_id
     * @param array  $fields List of ['key' => string, 'value' => mixed].
     */
    private function track_submission($form_id, $form_title, $submission_id, $fields) {
        try {
            $this->log('DataTools Pro PostHog Tracker: Submission triggered.');

            $site_id     = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : 'Unknown Site';
            $distinct_id = is_user_logged_in() ? get_current_user_id() : 'submission_' . $submission_id;

            $field_properties = [];
            foreach ($fields as $field) {
                $key   = $field['key'] ?? '';
                $value = $field['value'] ?? null;

                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
                }

                if ($key && $value !== null) {
                    $normalized_key = preg_replace('/_\d+$/', '', $key); // remove numeric suffixes
                    $normalized_key = preg_replace('/\s+/', '_', strtolower($normalized_key));
                    $normalized_key = preg_replace('/[^a-z0-9_]/', '', $normalized_key);
                    if ($normalized_key !== '') {
                        $field_properties[$normalized_key] = $value;
                    }
                }
            }

            // Identify the visitor as a known person (if we found an email and
            // they are not already identified), and use the email as the
            // distinct_id so this submission ties to their PostHog profile.
            $email = $this->find_email_in_properties($field_properties);
            if ($email) {
                $anon_distinct_id = $this->get_posthog_anon_distinct_id();

                if ($anon_distinct_id !== $email) {
                    // Only pass $anon_distinct_id when it looks like a true anonymous
                    // PostHog ID (not another person's email) - avoids merging two
                    // different identified people if this browser previously
                    // identified as someone else.
                    $merge_from = ($anon_distinct_id && !filter_var($anon_distinct_id, FILTER_VALIDATE_EMAIL))
                        ? $anon_distinct_id
                        : null;

                    $this->send_identify_event($email, $field_properties, $merge_from);
                }

                $distinct_id = $email;
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

            $this->send_to_posthog([
                'api_key'     => $this->api_key,
                'distinct_id' => $distinct_id,
                'event'       => !empty($this->event_name) ? $this->event_name : 'Form Submitted',
                'properties'  => $properties,
            ]);

        } catch (Exception $e) {
            $this->log('DataTools Pro PostHog Tracker Exception: ' . $e->getMessage());
        }
    }

    private function send_to_posthog($payload) {
        if (empty($this->api_key)) {
            $this->log('DataTools Pro PostHog Tracker: Missing API Key. Please configure in Settings > PostHog Tracker.');
            return;
        }

        if (empty($this->posthog_url)) {
            $this->log('DataTools Pro PostHog Tracker: Missing PostHog URL. Please configure in Settings > PostHog Tracker.');
            return;
        }

        $response = wp_remote_post($this->posthog_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            $this->log('DataTools Pro PostHog Tracker Error: ' . $response->get_error_message());
        } else {
            $this->log('DataTools Pro PostHog Tracker Response: ' . wp_remote_retrieve_body($response));
        }
    }
}

// Initialize plugin
new DTP_PostHog_Tracker();
