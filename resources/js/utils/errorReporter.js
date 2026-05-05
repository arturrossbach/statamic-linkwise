/**
 * Frontend Error Reporter — captures Vue render errors, runtime JS errors,
 * and unhandled promise rejections, then ships them to a Linkwise-local
 * endpoint so they end up in the support debug-export ZIP.
 *
 * Privacy: client-side scrubber strips query strings and masks /users/X
 * style paths before transmission. Server has a second pass.
 *
 * Recursion guard: we never report errors whose stack mentions our own
 * reporter URL or our own filenames — prevents infinite loops if posting
 * to /cp/linkwise/frontend-error itself fails and triggers another error.
 *
 * Dedup: same (message + stack) within 5 seconds is dropped. A single
 * broken render can otherwise fire dozens of identical errors.
 */

const ENDPOINT = '/cp/linkwise/frontend-error';
const DEDUP_WINDOW_MS = 5000;
const recentSignatures = new Map();
let reporterActive = false;

function scrubPii(text) {
    if (!text || typeof text !== 'string') return text;
    return text
        .replace(/(\?[^\s"'<>]+)/g, '?[redacted]')
        .replace(/\/(users|members|profile|account)\/[^/\s"'<>]+/gi, '/$1/[id]');
}

function isOwnError(stack) {
    if (!stack) return false;
    // Don't report errors thrown BY the reporter itself or in fetch() calls
    // hitting our own endpoint — would otherwise cascade.
    return /errorReporter\.js|frontend-error/i.test(stack);
}

function reportError({ kind, message, stack, source, url }) {
    if (reporterActive) return;
    if (!message) return;
    if (isOwnError(stack || '')) return;

    const signature = `${kind}|${message}|${(stack || '').slice(0, 200)}`;
    const now = Date.now();
    const lastSeen = recentSignatures.get(signature);
    if (lastSeen && now - lastSeen < DEDUP_WINDOW_MS) return;
    recentSignatures.set(signature, now);
    // GC the dedup map periodically so it doesn't grow unbounded.
    if (recentSignatures.size > 100) {
        for (const [sig, t] of recentSignatures) {
            if (now - t > DEDUP_WINDOW_MS * 2) recentSignatures.delete(sig);
        }
    }

    reporterActive = true;
    const csrfToken = (typeof Statamic !== 'undefined' && Statamic.$config)
        ? Statamic.$config.get('csrfToken')
        : null;
    const payload = {
        kind: kind || 'error',
        message: scrubPii(String(message).slice(0, 2000)),
        stack: scrubPii(String(stack || '').slice(0, 6000)),
        source: source ? String(source).slice(0, 200) : null,
        url: scrubPii(String(url || window.location.href).slice(0, 2000)),
    };

    fetch(ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        },
        body: JSON.stringify(payload),
        // Best-effort — never await, never throw to the caller.
    }).catch(() => {
        // Swallow — recursion guard already prevents an error here from
        // re-triggering the reporter, but we double-suppress just in case.
    }).finally(() => {
        reporterActive = false;
    });
}

/**
 * Install the Vue 3 errorHandler on a Vue app instance. Wraps any existing
 * handler so we don't clobber Statamic's chain (Statamic does set its own
 * for some lifecycle events).
 */
function installVueHandler(app) {
    if (!app || typeof app.config !== 'object') return false;
    if (app.config.__linkwiseErrorHandlerInstalled) return true;

    const previous = app.config.errorHandler;
    app.config.errorHandler = (err, instance, info) => {
        try {
            reportError({
                kind: 'vue',
                message: err && err.message ? err.message : String(err),
                stack: err && err.stack ? err.stack : '',
                source: info || 'vue-render',
                url: window.location.href,
            });
        } catch {
            // Reporter must not throw — silently drop.
        }
        // Preserve any pre-existing handler chain.
        if (typeof previous === 'function') {
            try { previous(err, instance, info); } catch {}
        }
    };
    app.config.__linkwiseErrorHandlerInstalled = true;
    return true;
}

/**
 * Wire up window-level + Vue-app-level handlers. Call once during addon
 * boot. Window handlers attach immediately; the Vue handler is retried
 * because `Statamic.$app` is created LATE in Statamic's boot() (after
 * bootedCallbacks fire — see vendor/statamic/cms/resources/js/bootstrap/
 * statamic.js around line 254).
 */
export function installErrorReporter(app) {
    // Try the Vue handler now; if app isn't ready, poll for it.
    if (!installVueHandler(app)) {
        let attempts = 0;
        const maxAttempts = 40; // 40 × 100ms = 4s window
        const timer = setInterval(() => {
            attempts++;
            // Re-fetch in case Statamic just finished booting in the
            // meantime — `app` arg captured at install time may be stale.
            const liveApp = (typeof Statamic !== 'undefined') ? Statamic.$app : null;
            if (installVueHandler(liveApp) || attempts >= maxAttempts) {
                clearInterval(timer);
            }
        }, 100);
    }

    // Runtime JS errors thrown outside Vue render (event handlers, async).
    // These fire regardless of Vue app status — install immediately.
    window.addEventListener('error', (event) => {
        try {
            reportError({
                kind: 'window',
                message: event.message || (event.error && event.error.message) || 'Unknown error',
                stack: event.error && event.error.stack ? event.error.stack : '',
                source: event.filename || '',
                url: window.location.href,
            });
        } catch {}
    });

    // Promise rejections without a .catch()
    window.addEventListener('unhandledrejection', (event) => {
        try {
            const reason = event.reason;
            const msg = reason && reason.message
                ? reason.message
                : (typeof reason === 'string' ? reason : JSON.stringify(reason).slice(0, 500));
            reportError({
                kind: 'unhandledrejection',
                message: msg,
                stack: reason && reason.stack ? reason.stack : '',
                source: 'promise',
                url: window.location.href,
            });
        } catch {}
    });
}
