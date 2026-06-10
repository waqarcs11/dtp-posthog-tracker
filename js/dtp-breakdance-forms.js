(function () {
    'use strict';

    var config = window.dtpBreakdanceConfig || {};
    var EVENT_NAME = config.eventName || 'Fundacann Form Submitted';
    var DEBUG = config.debug || false;
    console.log('EVENT_NAME==' + EVENT_NAME)
    function log() {
        if (DEBUG) {
            console.log.apply(console, ['[DTP PostHog Breakdance]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /**
     * Parse a URLEncoded string or FormData body into a plain object.
     */
    function parseBody(body) {
        var fields = {};

        if (!body) return fields;

        try {
            if (body instanceof FormData) {
                body.forEach(function (value, key) {
                    // Multi-value keys (e.g. checkboxes, multi-select): collect as array.
                    if (Object.prototype.hasOwnProperty.call(fields, key)) {
                        if (!Array.isArray(fields[key])) {
                            fields[key] = [fields[key]];
                        }
                        fields[key].push(value);
                    } else {
                        fields[key] = value;
                    }
                });
            } else if (typeof body === 'string') {
                // Try JSON first (Breakdance may send application/json).
                try {
                    var parsed = JSON.parse(body);
                    if (parsed && typeof parsed === 'object') {
                        Object.keys(parsed).forEach(function (k) { fields[k] = parsed[k]; });
                    }
                } catch (jsonErr) {
                    // Fall back to URL-encoded.
                    new URLSearchParams(body).forEach(function (value, key) {
                        if (Object.prototype.hasOwnProperty.call(fields, key)) {
                            if (!Array.isArray(fields[key])) {
                                fields[key] = [fields[key]];
                            }
                            fields[key].push(value);
                        } else {
                            fields[key] = value;
                        }
                    });
                }
            } else if (body instanceof URLSearchParams) {
                body.forEach(function (value, key) {
                    fields[key] = value;
                });
            }
        } catch (e) {
            console.log('[DTP PostHog] Could not parse request body:', e);
        }

        console.log('[DTP PostHog] Parsed form fields:', JSON.stringify(fields));
        return fields;
    }

    /**
     * Determine whether this admin-ajax.php request is a Breakdance form submission.
     * Logs the action name in debug mode so we can discover the real value on the test site.
     */
    function isBreakdanceForm(fields) {
        var action = (fields['action'] || '').toLowerCase();
        console.log('admin-ajax.php action detected:', action || '(none)');
        log('admin-ajax.php action detected:', action || '(none)');

        // Breakdance is expected to use an action name containing "breakdance" and "form".
        // If that doesn't match on the test site, check the debug log above and update this check.
        return action.indexOf('breakdance') !== -1 && action.indexOf('form') !== -1;
    }

    /**
     * Check whether a parsed AJAX response signals a successful form submission.
     */
    function isSuccessResponse(response) {
        console.log('check response');
        if (!response || typeof response !== 'object') return false;

        return response.type === 'success' || response.success === true || response.status === 'success';
    }

    /**
     * Read the correlation_id cookie set by dtp-posthog-client.js (if present).
     */
    function getCorrelationId() {
        var match = document.cookie.match(/(?:^|;\s*)dtp_correlation_id=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : null;
    }

    // Deduplication: track recently fired event signatures to prevent double-capture
    // when both XHR and fetch interceptors detect the same form submission.
    var _recentlyCaptured = {};
    var DEDUP_WINDOW_MS = 3000;

    function isDuplicate(formFields) {
        var key = (formFields['action'] || '') + '|' + (formFields['post_id'] || '') + '|' + (formFields['_wpnonce'] || formFields['nonce'] || '');
        var now = Date.now();
        if (_recentlyCaptured[key] && (now - _recentlyCaptured[key]) < DEDUP_WINDOW_MS) {
            return true;
        }
        _recentlyCaptured[key] = now;
        return false;
    }

    /**
     * Fire the PostHog capture call with cleaned form properties.
     * Uses navigator.sendBeacon directly so events survive page redirects
     * regardless of whether posthog.js has fully initialised.
     */
    function captureEvent(formFields, responseData) {
        if (isDuplicate(formFields)) {
            console.log('[DTP PostHog Breakdance] Duplicate event suppressed within dedup window.');
            return;
        }
        // Strip internal WordPress/AJAX params — not useful as PostHog properties.
        var SKIP = ['action', '_wpnonce', 'nonce', '_wp_http_referer', 'post_id'];
        var properties = {};

        // PostHog treats these as built-in system properties — rename them so they
        // land in custom event properties and are accessible to data pipelines.
        var POSTHOG_RESERVED = {
            'revenue': 'form_revenue',
            'amount':  'form_amount',
            'name':    'form_name'
        };

        Object.keys(formFields).forEach(function (key) {
            if (SKIP.indexOf(key) === -1 && formFields[key] != null) {
                // Strip the fields[...] wrapper Breakdance uses.
                // Also handle nested selects sent as fields[id][value] → id_value.
                var match = key.match(/^fields\[([^\]]+)\](?:\[([^\]]+)\])?$/);
                var cleanKey = match
                    ? (match[2] ? match[1] + '_' + match[2] : match[1])
                    : key;

                // Remap reserved PostHog property names.
                if (POSTHOG_RESERVED[cleanKey]) {
                    cleanKey = POSTHOG_RESERVED[cleanKey];
                }

                var val = formFields[key];
                // If Breakdance sends a select as a JSON object {label,value}, unwrap the value.
                if (val && typeof val === 'object' && !Array.isArray(val) && val.value !== undefined) {
                    val = val.value;
                }
                properties[cleanKey] = val;
            }
        });

        console.log('[DTP PostHog] Properties to send:', JSON.stringify(properties));

        // Pull any form metadata the Breakdance AJAX response includes.
        if (responseData && typeof responseData === 'object') {
            var d = responseData.data || responseData;
            if (d.formId)   properties['FormId']   = d.formId;
            if (d.form_id)  properties['FormId']   = d.form_id;
            if (d.formName) properties['FormType'] = d.formName;
        }

        properties['FormBuilder']  = 'Breakdance';
        properties['SiteId']       = window.location.hostname;
        properties['PageUrl']      = window.location.href;

        // Capture UTM parameters from the current page URL.
        var urlParams = new URLSearchParams(window.location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (param) {
            properties['$' + param] = urlParams.get(param) || null;
        });

        var correlationId = getCorrelationId();
        if (correlationId) {
            properties['correlation_id'] = correlationId;
        }

        // Try to get posthog distinct_id for continuity with other posthog events.
        var distinctId = (window.posthog && typeof window.posthog.get_distinct_id === 'function')
            ? window.posthog.get_distinct_id()
            : 'bf_' + Date.now();

        console.log('Capturing event "' + EVENT_NAME + '"', properties);

        var apiKey  = config.apiKey  || '';
        var apiHost = config.apiHost || 'https://app.posthog.com';

        // Primary: send directly via sendBeacon — works even if page is navigating away.
        if (apiKey && navigator.sendBeacon) {
            var payload = JSON.stringify({
                api_key:     apiKey,
                event:       EVENT_NAME,
                distinct_id: distinctId,
                properties:  properties,
                timestamp:   new Date().toISOString()
            });
            var sent = navigator.sendBeacon(apiHost + '/capture/', new Blob([payload], { type: 'application/json' }));
            console.log('sendBeacon dispatched:', sent);
            return;
        }

        // Fallback: posthog.js capture (requires library to be loaded).
        if (window.posthog && typeof window.posthog.capture === 'function') {
            window.posthog.capture(EVENT_NAME, properties);
        } else {
            console.log('PostHog not available and sendBeacon not supported, skipping capture.');
        }
    }

    // -------------------------------------------------------------------------
    // XHR interceptor
    // -------------------------------------------------------------------------
    var originalOpen = XMLHttpRequest.prototype.open;
    var originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this._dtpUrl = (typeof url === 'string') ? url : '';
        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
        console.log('in send');
        var xhr = this;

        if (xhr._dtpUrl.indexOf('admin-ajax.php') !== -1) {
            var fields = parseBody(body);

            if (isBreakdanceForm(fields)) {
                console.log('Breakdance form XHR detected, watching for success response.');

                xhr.addEventListener('load', function () {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (isSuccessResponse(response)) {
                            console.log('Breakdance form success confirmed via XHR.');
                            captureEvent(fields, response);
                        } else {
                            console.log('Breakdance AJAX response was not a success:', response);
                        }
                    } catch (e) {
                        console.log('Could not parse AJAX response as JSON:', e);
                    }
                });
            }
        }

        return originalSend.apply(this, arguments);
    };

    // -------------------------------------------------------------------------
    // Fetch interceptor (in case Breakdance uses fetch() instead of XHR)
    // -------------------------------------------------------------------------
    if (window.fetch) {
        console.log('in fetch');
        var originalFetch = window.fetch;

        window.fetch = function (resource, options) {
            var url = (typeof resource === 'string') ? resource :
                      (resource && resource.url) ? resource.url : '';

            if (url.indexOf('admin-ajax.php') !== -1) {
                var body = options && options.body ? options.body : null;
                var fields = parseBody(body);

                if (isBreakdanceForm(fields)) {
                    console.log('Breakdance form fetch() detected, watching for success response.');

                    return originalFetch.apply(this, arguments).then(function (response) {
                        // Clone so we don't consume the body for the caller.
                        response.clone().json().then(function (data) {
                            if (isSuccessResponse(data)) {
                                console.log('Breakdance form success confirmed via fetch.');
                                captureEvent(fields, data);
                            } else {
                                console.log('Breakdance fetch response was not a success:', data);
                            }
                        }).catch(function (e) {
                            console.log('Could not parse fetch response as JSON:', e);
                        });
                        return response;
                    });
                }
            }

            return originalFetch.apply(this, arguments);
        };
    }

    console.log('Breakdance form tracker initialized. Event name: "' + EVENT_NAME + '"');

})();
