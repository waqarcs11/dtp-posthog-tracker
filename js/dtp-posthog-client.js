(function () {
    'use strict';

    var UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var COOKIE_NAME = 'dtp_posthog_client_props';
    var SESSION_KEY = 'dtp_posthog_utms';

    function parseUA() {
        var ua = navigator.userAgent || '';
        var browser = 'Unknown';
        var browserVersion = '';
        var os = 'Unknown';
        var osVersion = '';

        // Browser detection
        if (/Edg\/(\d+[\.\d]*)/.test(ua)) {
            browser = 'Edge';
            browserVersion = RegExp.$1;
        } else if (/OPR\/(\d+[\.\d]*)/.test(ua)) {
            browser = 'Opera';
            browserVersion = RegExp.$1;
        } else if (/Chrome\/(\d+[\.\d]*)/.test(ua) && !/Chromium/.test(ua)) {
            browser = 'Chrome';
            browserVersion = RegExp.$1;
        } else if (/Safari\/(\d+[\.\d]*)/.test(ua) && !/Chrome/.test(ua)) {
            browser = 'Safari';
            browserVersion = (/Version\/(\d+[\.\d]*)/.test(ua)) ? RegExp.$1 : '';
        } else if (/Firefox\/(\d+[\.\d]*)/.test(ua)) {
            browser = 'Firefox';
            browserVersion = RegExp.$1;
        } else if (/MSIE\s(\d+[\.\d]*)/.test(ua) || /Trident.*rv:(\d+[\.\d]*)/.test(ua)) {
            browser = 'Internet Explorer';
            browserVersion = RegExp.$1;
        }

        // OS detection
        if (/Windows NT (\d+[\.\d]*)/.test(ua)) {
            os = 'Windows';
            var ntVersion = RegExp.$1;
            var windowsVersions = { '10.0': '10/11', '6.3': '8.1', '6.2': '8', '6.1': '7' };
            osVersion = windowsVersions[ntVersion] || ntVersion;
        } else if (/Mac OS X (\d+[_\.\d]*)/.test(ua)) {
            os = 'Mac OS X';
            osVersion = RegExp.$1.replace(/_/g, '.');
        } else if (/CrOS/.test(ua)) {
            os = 'Chrome OS';
        } else if (/Android (\d+[\.\d]*)/.test(ua)) {
            os = 'Android';
            osVersion = RegExp.$1;
        } else if (/iPhone OS (\d+[_\.\d]*)/.test(ua) || /iPad.*OS (\d+[_\.\d]*)/.test(ua)) {
            os = 'iOS';
            osVersion = (RegExp.$1 || '').replace(/_/g, '.');
        } else if (/Linux/.test(ua)) {
            os = 'Linux';
        }

        return { browser: browser, browserVersion: browserVersion, os: os, osVersion: osVersion };
    }

    function getDeviceType() {
        var ua = navigator.userAgent || '';
        if (/Tablet|iPad/i.test(ua)) return 'Tablet';
        if (/Mobile|iPhone|Android.*Mobile/i.test(ua)) return 'Mobile';
        return 'Desktop';
    }

    function getReferringDomain() {
        var referrer = document.referrer;
        if (!referrer) return '';
        try {
            return new URL(referrer).hostname;
        } catch (e) {
            return '';
        }
    }

    function getUTMs() {
        var params = {};
        var stored = {};

        // Read from sessionStorage (persists across page navigations)
        try {
            var raw = sessionStorage.getItem(SESSION_KEY);
            if (raw) stored = JSON.parse(raw);
        } catch (e) { /* ignore */ }

        // Parse current URL for UTMs
        var search = window.location.search;
        var hasNewUtms = false;
        for (var i = 0; i < UTM_PARAMS.length; i++) {
            var key = UTM_PARAMS[i];
            var match = new RegExp('[?&]' + key + '=([^&]*)').exec(search);
            if (match) {
                params[key] = decodeURIComponent(match[1]);
                hasNewUtms = true;
            }
        }

        // If current URL has UTMs, update sessionStorage
        if (hasNewUtms) {
            var merged = {};
            for (var k in stored) merged[k] = stored[k];
            for (var k in params) merged[k] = params[k];
            try { sessionStorage.setItem(SESSION_KEY, JSON.stringify(merged)); } catch (e) { /* ignore */ }
            return merged;
        }

        return stored;
    }

    function setCookie(name, value) {
        var encoded = encodeURIComponent(value);
        document.cookie = name + '=' + encoded + '; path=/; SameSite=Lax; max-age=1800';
    }

    function collect() {
        var parsed = parseUA();
        var utms = getUTMs();

        var props = {
            '$current_url': window.location.href,
            '$pathname': window.location.pathname,
            '$host': window.location.hostname,
            '$browser': parsed.browser,
            '$browser_version': parsed.browserVersion,
            '$device_type': getDeviceType(),
            '$os': parsed.os,
            '$os_version': parsed.osVersion,
            '$screen_height': screen.height,
            '$screen_width': screen.width,
            '$viewport_height': window.innerHeight,
            '$viewport_width': window.innerWidth,
            '$referrer': document.referrer,
            '$referring_domain': getReferringDomain(),
            '$lib': 'dtp-posthog-tracker'
        };

        // Add UTMs
        for (var key in utms) {
            if (utms.hasOwnProperty(key)) {
                props[key] = utms[key];
            }
        }

        setCookie(COOKIE_NAME, JSON.stringify(props));
    }

    // Collect on page load
    collect();

    // Update on resize (viewport dimensions change)
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(collect, 500);
    });
})();
