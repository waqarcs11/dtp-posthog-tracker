<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$dtp_posthog_options = [
    'dtp_posthog_api_key',
    'dtp_posthog_url',
    'dtp_posthog_event_name',
    'dtp_posthog_ui_host',
    'dtp_posthog_defaults',
    'dtp_posthog_person_profiles',
];

foreach ($dtp_posthog_options as $dtp_posthog_option) {
    delete_option($dtp_posthog_option);
}
