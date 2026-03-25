(function () {
    'use strict';

    var COOKIE_NAME = 'dtp_correlation_id';

    function generateUUID() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function setCookie(value) {
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax; max-age=1800';
    }

    function getCorrelationId() {
        var match = document.cookie.match(/(?:^|;\s*)dtp_correlation_id=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : null;
    }

    // Generate and persist correlation_id on page load so it's ready before any AJAX fires
    if (!getCorrelationId()) {
        setCookie(generateUUID());
    }

    function onSubmitClick() {
        var correlationId = getCorrelationId();
        console.log('fundcanna_form_submit_capture');
        if (window.posthog && typeof window.posthog.capture === 'function') {
            window.posthog.capture('fundcanna_form_submit_attempt', {
                correlation_id: correlationId
            }, { transport: 'sendBeacon' });
        }
    }
    console.log('start submitting');
    document.addEventListener('click', function (e) {
        var submitEl = e.target.closest('button[type="submit"], button:not([type]), input[type="submit"], input[type="button"]');
        if (submitEl && submitEl.closest('form')) {
            console.log('checked if submit');
            onSubmitClick();
        }
    });

})();
