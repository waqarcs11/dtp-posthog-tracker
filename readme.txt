=== DTP PostHog Tracker ===
Contributors: datatoolspro
Tags: posthog, analytics, forms, ninja forms, breakdance
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Ninja Forms and Breakdance form submissions to PostHog as events, with all field values and automatic person identification.

== Description ==

DTP PostHog Tracker captures form submissions on your WordPress site and sends them to your PostHog project as events.

* Supports Ninja Forms (server-side) and Breakdance forms (client-side, fired after a confirmed successful submission).
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

= Does it need Ninja Forms? =

No. Ninja Forms submissions are tracked when Ninja Forms is active. Breakdance form tracking works independently.

= Can I use a reverse proxy? =

Yes. Enter your proxy's capture URL as the PostHog URL and set the PostHog UI Host to your real PostHog host.

= What is the default event name? =

"Form Submitted". You can change it in the settings.

== Changelog ==

= 1.1.0 =
* Settings page for API key, URL, event name, UI host, defaults version and person profiles.
* Email-based person identification.
* PostHog snippet is now enqueued via the WordPress script API.
* Debug logging only when WP_DEBUG and WP_DEBUG_LOG are enabled.
* Added uninstall cleanup.

== Upgrade Notice ==

= 1.1.0 =
The default event name changed from a site-specific value to "Form Submitted". Sites that saved an event name in settings are unaffected.
