=== DataTools Pro PostHog Tracker ===
Contributors: datatoolspro
Tags: posthog, analytics, forms, contact form 7, wpforms
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Contact Form 7, WPForms, Gravity Forms, Ninja Forms and Breakdance form submissions to PostHog as events, with all field values and automatic person identification.

== Description ==

DataTools Pro PostHog Tracker captures form submissions on your WordPress site and sends them to your PostHog project as events.

* Supports Contact Form 7, WPForms, Gravity Forms and Ninja Forms (server-side, after a successful submission) and Breakdance forms (client-side, after a confirmed success).
* Sends every form field as an event property, plus form ID, form title and site info.
* Identifies visitors in PostHog by email address and merges their anonymous browsing history into their person profile.
* Works with PostHog Cloud (US/EU) or a self-hosted reverse proxy.
* Deduplicates events so each submission is sent once, and links the form submit attempt to the final event with a correlation ID.

= External services =

This plugin connects to a PostHog instance that you configure under Settings > PostHog Tracker, and cannot send data until you do.

1. **Browser script:** the PostHog JavaScript library (`array.js`) is loaded from the PostHog host you configure (for PostHog Cloud, `*-assets.i.posthog.com`) and sends pageview and form-submit-attempt events to that host from your visitors' browsers.
2. **Form submissions:** when a form is submitted, the form field values (which may include names, email addresses and phone numbers), form ID and title, your site's domain, and the visitor's PostHog anonymous ID are sent to your PostHog capture URL.

PostHog is provided by PostHog, Inc. Terms of service: https://posthog.com/terms. Privacy policy: https://posthog.com/privacy.

You are responsible for informing your visitors and obtaining any consent required by the privacy laws that apply to you (for example GDPR/CCPA).

== Installation ==

1. Upload the `dtp-posthog-tracker` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to Settings > PostHog Tracker and enter your PostHog project API key (starts with `phc_`) and your PostHog capture URL.
4. Submit a test form and check PostHog > Activity for the event.

== Frequently Asked Questions ==

= Does it need a specific form plugin? =

No. Each supported form plugin (Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Breakdance) is tracked only if it is installed and active, and they work independently.

= Can I use a reverse proxy? =

Yes. Enter your proxy's capture URL as the PostHog URL and set the PostHog UI Host to your real PostHog host.

= What is the default event name? =

"Form Submitted". You can change it in the settings.

== Changelog ==

= 1.1.0 =
* Added Contact Form 7, WPForms and Gravity Forms support.
* Settings page for API key, URL, event name, UI host, defaults version and person profiles.
* Email-based person identification.
* PostHog snippet is now enqueued via the WordPress script API.
* Debug logging only when WP_DEBUG and WP_DEBUG_LOG are enabled.
* Added uninstall cleanup.

== Upgrade Notice ==

= 1.1.0 =
The default event name changed from a site-specific value to "Form Submitted". Sites that saved an event name in settings are unaffected.
