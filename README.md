# DataTools Pro PostHog Tracker — Full Plugin Documentation

**Plugin Name:** DataTools Pro PostHog Tracker  
**Version:** 1.1.0  
**Author:** DataTools Pro  
**WordPress Hook Compatibility:** Breakdance Form Builder, Ninja Forms, Contact Form 7, WPForms, Gravity Forms  

---

## Table of Contents

1. [What Is This Plugin?](#1-what-is-this-plugin)
2. [Why It Was Built](#2-why-it-was-built)
3. [High-Level Architecture](#3-high-level-architecture)
4. [File Structure](#4-file-structure)
5. [Configuration & Settings](#5-configuration--settings)
6. [How Each File Works](#6-how-each-file-works)
   - [dtp-posthog-tracker.php](#61-dtp-posthog-trackerphp)
   - [js/dtp-posthog-client.js](#62-jsdtp-posthog-clientjs)
   - [js/dtp-breakdance-forms.js](#63-jsdtp-breakdance-formsjs)
7. [Full Data Flow — Step by Step](#7-full-data-flow--step-by-step)
8. [PostHog → Salesforce Pipeline](#8-posthog--salesforce-pipeline)
9. [Deduplication Guard](#9-deduplication-guard)
10. [Correlation ID System](#10-correlation-id-system)
11. [Known PostHog Reserved Property Conflicts](#11-known-posthog-reserved-property-conflicts)
12. [Admin Settings Page](#12-admin-settings-page)
13. [Debugging](#13-debugging)
14. [PostHog Person Identification](#14-posthog-person-identification)
15. [Limitations & Notes](#15-limitations--notes)

---

## 1. What Is This Plugin?

DataTools Pro PostHog Tracker is a custom WordPress plugin that **captures Breakdance form submissions and sends them as events to PostHog** (a product analytics platform). Those events are then forwarded from PostHog to **Salesforce** via a PostHog Data Pipeline, creating or updating Salesforce records automatically whenever a user submits a form on the website.

In plain terms:

> User fills out a form → submits → plugin captures all field values → sends them to PostHog → PostHog forwards to Salesforce.

---

## 2. Why It Was Built

Breakdance (the page builder used on the site) does not have a native PostHog integration. Standard PostHog JS auto-capture cannot reliably intercept AJAX-based form submissions (Breakdance submits forms via background AJAX, not a traditional page reload).

This plugin solves that by:

- Intercepting the AJAX request that Breakdance fires when a form is submitted
- Waiting for a confirmed success response before firing the analytics event
- Sending all form field values as PostHog event properties
- Ensuring the event fires exactly once per submission (deduplication)

---

## 3. High-Level Architecture

```
[WordPress Site — Breakdance Form]
        |
        | User submits form
        v
[Breakdance fires AJAX POST to admin-ajax.php]
        |
        | Intercepted by dtp-breakdance-forms.js (XHR + fetch interceptors)
        v
[Plugin confirms success response]
        |
        | Builds event properties from form fields
        v
[navigator.sendBeacon → PostHog /capture/ API]
        |
        | PostHog receives event
        v
[PostHog Data Pipeline (configured in PostHog)]
        |
        | Transforms & forwards
        v
[Salesforce — creates/updates record]
```

Additionally, on page load:

```
[Page loads]
        |
        v
[dtp-posthog-client.js runs]
        |
        ├── Initialises PostHog JS library (via PHP snippet in <head>)
        └── Generates a correlation_id cookie (UUID) for session linking
```

---

## 4. File Structure

```
dtp-posthog-tracker/
│
├── dtp-posthog-tracker.php       — Main plugin file. PHP class, admin settings,
│                                   script enqueuing, PostHog JS snippet injection.
│
└── js/
    ├── dtp-posthog-client.js     — Runs on every page. Generates correlation_id
    │                               cookie. Fires a submit-attempt event on button click.
    │
    └── dtp-breakdance-forms.js   — Core tracker. Intercepts Breakdance AJAX form
                                    submissions and sends the PostHog capture event.
```

---

## 5. Configuration & Settings

The plugin adds a settings page in the WordPress admin at:

**Settings → PostHog Tracker**

| Setting | Description | Example |
|---|---|---|
| PostHog API Key | Your PostHog project API key | `phc_xxxxxxxxxxxxxxxx` |
| PostHog URL | The PostHog capture endpoint URL | `https://app.posthog.com/capture/` |
| Event Name | The name of the event sent to PostHog on form submission | `Form Submitted` |

**Important:** All three fields must be filled in. If the API Key or URL is missing, the plugin will not send any events and will log a warning to the PHP error log.

The API host (e.g., `https://app.posthog.com`) is automatically derived from the PostHog URL — you do not need to enter it separately.

---

## 6. How Each File Works

### 6.1 `dtp-posthog-tracker.php`

This is the main PHP plugin file. It is a single class `DTP_PostHog_Tracker` that is instantiated once on plugin load.

#### Responsibilities:

**A. Reads settings from WordPress options:**
```
dtp_posthog_api_key    → PostHog API key
dtp_posthog_url        → PostHog capture URL
dtp_posthog_event_name → Event name
```

**B. Injects PostHog JS snippet into `<head>` (`inject_posthog_snippet`)**

Outputs a `<script>` tag that loads the PostHog JS library (array.js) and calls `posthog.init()` with the configured API key and host. This initialises the PostHog library on every frontend page. The snippet includes a guard (`if (!window.posthog || !window.posthog.__loaded)`) to prevent double-initialisation.

**C. Enqueues `dtp-posthog-client.js` (`enqueue_client_script`)**

Loads the correlation ID script on all frontend pages. Uses `filemtime()` as the version for automatic cache busting when the file changes.

**D. Enqueues `dtp-breakdance-forms.js` (`enqueue_breakdance_script`)**

Loads the form tracker script and passes configuration to it via `wp_localize_script()`:

```javascript
window.dtpBreakdanceConfig = {
    eventName: "Form Submitted",
    debug:     false,                        // true when WP_DEBUG is on
    apiKey:    "phc_xxxx",
    apiHost:   "https://app.posthog.com"
}
```

**E. Admin settings page (`add_settings_page`, `register_settings`, `render_settings_page`)**

Registers settings under the group `dtp_posthog_settings_group`. Renders the settings form at `options-general.php?page=dtp-posthog-settings`. Adds a "Settings" link directly on the plugin listing page.

**F. Admin notice (`admin_notice_missing_settings`)**

Shows a yellow warning banner in the WP admin if the API key or URL is not configured. Hidden on the settings page itself.

**G. Ninja Forms hook (`send_form_to_posthog`)**

*Note: This method is a legacy server-side handler for Ninja Forms (`ninja_forms_after_submission`). It is only active if Ninja Forms is installed. For Breakdance forms, this does NOT fire — all Breakdance tracking is handled client-side by `dtp-breakdance-forms.js`.*

When triggered (Ninja Forms only), it:
- Extracts all field key/value pairs from the submission data
- Normalises field names (lowercase, underscores, strip numeric suffixes)
- Reads the `dtp_correlation_id` cookie from the browser (passed via the HTTP request)
- Builds a PostHog payload and sends it via `wp_remote_post()` (server-side HTTP request)

**H. `send_to_posthog()` (private method)**

Low-level helper that performs the actual `wp_remote_post()` to the configured PostHog URL. Logs success or error to the PHP error log.

---

### 6.2 `js/dtp-posthog-client.js`

This script runs on **every page load** on the frontend. It is small and lightweight.

#### What it does:

**A. Generates a `correlation_id` cookie**

On page load, if no `dtp_correlation_id` cookie exists, it generates a UUID (using `crypto.randomUUID()` if available, otherwise a manual fallback) and stores it as a cookie:

```
Name:     dtp_correlation_id
Value:    UUID (e.g., 5a0e34cc-70be-4050-9c1a-aadb3e706906)
Path:     /
SameSite: Lax
Max-age:  1800 seconds (30 minutes)
```

This cookie persists for 30 minutes and is used to link the form submission event back to the user's browsing session, even if the page navigates away after submission.

**B. Fires a submit-attempt event on button click**

Listens for clicks on any submit button inside a form. When detected, it fires a `dtp_form_submit_attempt` PostHog event (using the PostHog JS library) that includes the `correlation_id`. This is a pre-submission signal — it fires when the user clicks submit, before the AJAX response confirms success.

---

### 6.3 `js/dtp-breakdance-forms.js`

This is the **core tracking script**. It intercepts Breakdance's AJAX form submissions and fires the PostHog event when a submission succeeds.

#### How it intercepts AJAX:

Breakdance submits forms via AJAX to WordPress's `admin-ajax.php` endpoint. There is no WordPress hook or JavaScript event that reliably fires after a Breakdance form succeeds — so the plugin monkey-patches both `XMLHttpRequest` and `fetch()` to intercept all outgoing AJAX requests.

**XHR Interceptor:**
```
Patches XMLHttpRequest.prototype.open  → records the URL of every XHR request
Patches XMLHttpRequest.prototype.send  → inspects the request body if URL contains admin-ajax.php
```

**Fetch Interceptor:**
```
Replaces window.fetch → wraps every fetch call, inspects body if URL contains admin-ajax.php
```

Both interceptors use the same pipeline:

```
1. parseBody(body)       → Converts FormData / URL-encoded string / JSON / URLSearchParams
                           into a plain JavaScript object of key→value pairs.

2. isBreakdanceForm()    → Checks if the 'action' field contains both "breakdance" AND "form".
                           If not, the request is ignored completely.

3. Wait for response     → XHR: listens for the 'load' event
                           Fetch: chains a .then() on the returned Promise

4. isSuccessResponse()   → Checks response JSON for:
                           response.type === 'success'    OR
                           response.success === true      OR
                           response.status === 'success'

5. captureEvent()        → If success confirmed, builds and sends the PostHog event.
```

#### `parseBody(body)` — Body Parsing

Handles four formats that Breakdance might use:

| Format | How detected | How parsed |
|---|---|---|
| `FormData` object | `body instanceof FormData` | `.forEach()` — multi-value keys collected as arrays |
| JSON string | `typeof body === 'string'` → try `JSON.parse()` | `Object.keys()` |
| URL-encoded string | `typeof body === 'string'` → JSON fails → fallback | `URLSearchParams.forEach()` |
| `URLSearchParams` object | `body instanceof URLSearchParams` | `.forEach()` |

All parsing results in a plain `{ key: value }` object.

#### `captureEvent(formFields, responseData)` — Event Building

Builds the PostHog event properties:

**Step 1 — Deduplication check** (see Section 9)

**Step 2 — Skip internal WordPress fields:**
```
action, _wpnonce, nonce, _wp_http_referer, post_id
```

**Step 3 — Clean field keys:**

Breakdance submits fields as `fields[fieldname]`. The regex `/^fields\[([^\]]+)\](?:\[([^\]]+)\])?$/` strips the wrapper:
- `fields[email]` → `email`
- `fields[first_name]` → `first_name`
- `fields[select][value]` → `select_value`

**Step 4 — Remap PostHog reserved names** (see Section 11)

**Step 5 — Unwrap object values:**
If a field value is a JSON object with a `value` key (e.g., `{ label: "Option A", value: "opt_a" }`), the plugin extracts just the `value`. This handles select dropdowns that some builders encode as objects.

**Step 6 — Add metadata from AJAX response:**
```javascript
FormId      ← response.data.formId or response.data.form_id
FormType    ← response.data.formName
```

**Step 7 — Add standard properties:**
```javascript
FormBuilder  = "Breakdance"
SiteId       = window.location.hostname
PageUrl      = window.location.href
correlation_id = (from dtp_correlation_id cookie, if present)
```

**Step 8 — Resolve distinct_id:**
Uses `posthog.get_distinct_id()` if the PostHog library is loaded. Falls back to `'bf_' + Date.now()` if not available.

**Step 9 — Send to PostHog:**

Primary method: `navigator.sendBeacon()` — fires-and-forgets, works even if the page navigates away immediately after submission.

Fallback: `posthog.capture()` — used only if `sendBeacon` is not available (very old browsers).

---

## 7. Full Data Flow — Step by Step

1. **Page loads** → `dtp-posthog-client.js` runs → generates `dtp_correlation_id` cookie if not present → PostHog JS library is initialised.

2. **User fills out the Breakdance form** and clicks Submit.

3. **`dtp-posthog-client.js`** detects the button click → fires `dtp_form_submit_attempt` event to PostHog (pre-submission signal).

4. **Breakdance JS** serialises the form and fires an AJAX POST to `admin-ajax.php` with action `breakdance_form_submit` (or similar).

5. **`dtp-breakdance-forms.js`** intercepts the request (via XHR or fetch interceptor) → calls `parseBody()` → calls `isBreakdanceForm()` → confirms it's a Breakdance form submission.

6. **WordPress/Breakdance** processes the form on the server and returns a JSON response.

7. The interceptor receives the response → calls `isSuccessResponse()` → confirms the submission succeeded.

8. **`captureEvent()`** is called → deduplication check passes → event properties are built from all form fields.

9. **`navigator.sendBeacon()`** sends the event payload to `https://app.posthog.com/capture/` (or configured host).

10. **PostHog** receives the event, stores it, and triggers the configured **Data Pipeline**.

11. **PostHog Data Pipeline** maps event properties to Salesforce fields and creates/updates the Salesforce record.

---

## 8. PostHog → Salesforce Pipeline

The PostHog side of the integration is a **Data Pipeline** configured directly in the PostHog dashboard (not part of this plugin).

**How it works:**
- PostHog listens for the configured event name (e.g., `Form Submitted`)
- When the event arrives, the pipeline transforms the event properties and makes an API call to Salesforce
- Each event property (e.g., `email`, `firstname`, `lastname`, `form_revenue`) is mapped to a Salesforce field

**Custom Salesforce fields in use:**

| PostHog Property | Salesforce Field |
|---|---|
| `email` | Standard Email field |
| `firstname` | Standard First Name |
| `lastname` | Standard Last Name |
| `form_revenue` | `Approximate_Yearly_Sales__c` (custom picklist field) |
| `form_id` | Form identifier |

**Important:** The Salesforce field `Approximate_Yearly_Sales__c` is a **picklist** (single selection). The Breakdance form dropdown for yearly sales must therefore be set to single-select only, and its values must match the picklist values defined in Salesforce exactly.

---

## 9. Deduplication Guard

**Problem:** Both the XHR interceptor and the fetch interceptor run simultaneously. If Breakdance fires more than one AJAX request to `admin-ajax.php` that both return a success response (e.g., a submit request followed by a redirect/confirmation request), `captureEvent()` would fire twice — sending two identical events to PostHog and creating two Salesforce records.

**Solution:** A simple time-based deduplication guard.

```javascript
var _recentlyCaptured = {};
var DEDUP_WINDOW_MS = 3000; // 3 seconds

function isDuplicate(formFields) {
    var key = action + '|' + post_id + '|' + nonce;
    var now = Date.now();
    if (_recentlyCaptured[key] && (now - _recentlyCaptured[key]) < DEDUP_WINDOW_MS) {
        return true; // suppress duplicate
    }
    _recentlyCaptured[key] = now;
    return false;
}
```

**How the key is built:**
The deduplication key is composed of three fields from the form POST body:
- `action` — the WordPress AJAX action name (unique per form type)
- `post_id` — the page the form is on
- `_wpnonce` or `nonce` — a unique security token generated per page load

This combination is stable per-submission: two calls for the exact same form submission will produce the same key. Any second call within 3 seconds is suppressed.

---

## 10. Correlation ID System

The `correlation_id` is a UUID generated by `dtp-posthog-client.js` on page load and stored in a browser cookie (`dtp_correlation_id`) for 30 minutes.

**Purpose:** It links two separate events together:
1. `dtp_form_submit_attempt` — fired on button click (before AJAX response)
2. `Form Submitted` — fired after confirmed success

Both events include the same `correlation_id`, allowing PostHog to correlate the attempt with the successful submission for analytics funnels.

**Server-side use:** The PHP `send_form_to_posthog()` method (for Ninja Forms) also reads this cookie from the HTTP request headers and includes it in the server-side PostHog event, maintaining the same correlation across both client and server events.

---

## 11. Known PostHog Reserved Property Conflicts

Some property names are treated by PostHog as **system-level built-in properties** rather than custom event properties. When a property with one of these names is sent, PostHog absorbs it into its own internal analytics system and it becomes unavailable to Data Pipelines (like the Salesforce connector).

The plugin remaps these keys before sending:

| Original Form Field Name | Sent to PostHog As | Reason |
|---|---|---|
| `revenue` | `form_revenue` | PostHog uses `revenue` for built-in revenue analytics |
| `amount` | `form_amount` | Conflicts with PostHog's monetary tracking |
| `name` | `form_name` | Conflicts with PostHog person property |

**Practical example:** The Breakdance form field `fields[revenue]` (the yearly sales dropdown) was originally sent as `revenue`. PostHog displayed it with a system flag icon and the Salesforce pipeline could not read it. After remapping to `form_revenue`, it appears as a standard custom event property and the pipeline maps it correctly to `Approximate_Yearly_Sales__c`.

---

## 12. Admin Settings Page

Located at: **WordPress Admin → Settings → PostHog Tracker**

The page uses the standard WordPress Settings API. Fields are:

- **PostHog API Key** — stored as `dtp_posthog_api_key` in `wp_options`. Sanitised with `sanitize_text_field`.
- **PostHog URL** — stored as `dtp_posthog_url`. Sanitised with `esc_url_raw`. Should be the full capture endpoint, e.g., `https://app.posthog.com/capture/`.
- **Event Name** — stored as `dtp_posthog_event_name`. The name of the PostHog event that will appear in the PostHog dashboard and trigger the Data Pipeline.

A "Settings" shortcut link is added to the plugin row on the **Plugins** page.

If either the API key or URL is missing, a dismissible yellow admin notice appears across all admin pages (except the settings page itself) reminding the administrator to configure the plugin.

---

## 13. Debugging

**Browser Console**

Open DevTools → Console when submitting a form. You will see:

| Log Message | Meaning |
|---|---|
| `EVENT_NAME== Form Submitted` | Script loaded, event name confirmed |
| `admin-ajax.php action detected: breakdance_form_submit` | A Breakdance AJAX request was intercepted |
| `Breakdance form XHR detected` or `fetch() detected` | Confirmed as a Breakdance form request |
| `[DataTools Pro PostHog] Parsed form fields: {...}` | Shows all fields extracted from the form body |
| `[DataTools Pro PostHog] Properties to send: {...}` | Shows the final payload before sending |
| `Capturing event "Form Submitted"` | Event is about to fire |
| `sendBeacon dispatched: true` | Event sent successfully via sendBeacon |
| `Duplicate event suppressed` | Deduplication guard blocked a second fire |

**PHP Error Log**

| Log Message | Meaning |
|---|---|
| `🚀 DataTools Pro PostHog Tracker: Submission triggered.` | Ninja Forms submission received (not Breakdance) |
| `✅ DataTools Pro PostHog Tracker Response: ...` | Server-side PostHog call succeeded |
| `❌ DataTools Pro PostHog Tracker Error: ...` | Server-side PostHog call failed |
| `⚠️ Missing API Key / URL` | Plugin not configured |

**Enabling Debug Mode**

Set `WP_DEBUG = true` in `wp-config.php`. This enables additional verbose logging from the `log()` function inside `dtp-breakdance-forms.js`.

---

## 14. PostHog Person Identification

By default, PostHog tracks every visitor under an **anonymous `distinct_id`** (a random UUID, also stored as `$device_id`). All pageviews, clicks, and the `dtp_form_submit_attempt` event are recorded against that anonymous ID until the visitor is **identified** as a known person (e.g., via their email address).

This plugin automatically identifies the visitor as soon as a form submission contains an email address — for **both** Breakdance and Ninja Forms.

### 14.1 Breakdance (client-side — `dtp-breakdance-forms.js`)

Inside `captureEvent()`, before the main "Form Submitted" event is sent:

1. **`findEmail(properties)`** — scans every submitted field value and returns the first one matching an email regex (`/^[^\s@]+@[^\s@]+\.[^\s@]+$/`). No specific field name/key is required.
2. **Already-identified check** — compares `posthog.get_distinct_id()` with `posthog.get_property('$device_id')`. If they differ, the visitor is already identified and the step is skipped.
3. **`buildIdentifyProperties(properties, email)`** — builds the `$set` payload: `email` plus any field whose key matches `/name|phone|mobile|tel/i` (e.g. `first_name`, `last_name`, `phone`).
4. **`window.posthog.identify(email, setProps)`** — links the anonymous profile to the email-based identity. This updates the locally persisted `distinct_id` immediately, so the "Form Submitted" event captured right after (which reads `posthog.get_distinct_id()`) is recorded against the same identified person.

### 14.2 Ninja Forms (server-side — `dtp-posthog-tracker.php`)

Inside `send_form_to_posthog()`, after `$field_properties` is built:

1. **`find_email_in_properties()`** — same approach as the JS side, using `filter_var($value, FILTER_VALIDATE_EMAIL)` over all field values.
2. **`get_posthog_anon_distinct_id()`** — reads the anonymous `distinct_id` from the `ph_<api_key>_posthog` cookie that posthog.js persists in the browser.
3. **Already-identified check** — if the anonymous `distinct_id` already equals the email, identification is skipped.
4. **`send_identify_event()`** — sends a `$identify` event to PostHog with:
   ```json
   {
     "event": "$identify",
     "distinct_id": "user@example.com",
     "properties": {
       "$set": { "email": "user@example.com", "first_name": "...", "phone": "..." },
       "$anon_distinct_id": "<previous anonymous distinct_id from cookie>"
     }
   }
   ```
   `$anon_distinct_id` tells PostHog to merge the anonymous person's history into the new identified profile.
5. The main Ninja Forms event's `distinct_id` is then set to the email address, so it's recorded against the same identified person.

### 14.3 Notes

- Identification only happens **once per visitor** — once `distinct_id` no longer matches the anonymous `$device_id` (Breakdance) or the cookie's `distinct_id` already equals the email (Ninja Forms), the `$identify` call is skipped.
- If no field value matches the email regex, identification is skipped entirely and the event continues to use the anonymous `distinct_id`.
- This relies on posthog.js's default cookie persistence (`ph_<api_key>_posthog`) being enabled so the server-side handler can read the anonymous `distinct_id`.

---

## 15. Limitations & Notes

- **Breakdance only (client-side):** The client-side tracker (`dtp-breakdance-forms.js`) is built specifically for Breakdance forms. It relies on Breakdance posting to `admin-ajax.php` with an action name containing both "breakdance" and "form". If Breakdance ever changes this, the `isBreakdanceForm()` check will need updating.

- **Ninja Forms (server-side only):** The PHP `send_form_to_posthog()` method handles Ninja Forms via a server-side hook. This path is completely separate from the Breakdance path and only activates if Ninja Forms is installed.

- **sendBeacon and page navigation:** Using `navigator.sendBeacon` means the event will fire even if the page immediately redirects after submission. The browser queues the beacon and sends it in the background.

- **No file upload field support:** `FormData` file inputs are ignored since PostHog properties cannot hold binary data.

- **PostHog library must be initialised:** The fallback `posthog.capture()` path requires the PostHog JS library to have loaded. On slow connections, if the form is submitted before the library loads, `sendBeacon` will be used instead — which does not require the library at all.

- **Salesforce picklist values must match exactly:** The dropdown options in Breakdance must have `value` attributes that exactly match the API names of the picklist values in Salesforce (e.g., `Pre-Revenue`, `$1-$150k`). Case and spacing matter.

- **Cookie lifetime:** The `correlation_id` cookie expires after 30 minutes. If a user leaves the page open for more than 30 minutes before submitting, a new correlation ID will be generated, breaking the link between the submit-attempt and submit-success events.
