/**
 * Safe-by-default sessionStorage wrapper.
 *
 * Browser sessionStorage throws in three legitimate scenarios:
 *   - Safari Private Mode (quota = 0)
 *   - Storage-disabled enterprise policies
 *   - Quota-exceeded after long sessions
 *
 * Linkwise uses sessionStorage purely for UX niceties (recovery banners,
 * collapsed-state persistence, deduped toasts). None of it is load-bearing —
 * the app must keep working when storage isn't writable.
 *
 * Before this helper, ~6 places across services + LinkwiseLayout each
 * wrapped raw sessionStorage calls in try/catch with similar comments.
 * Adding a new persisted preference meant copy-pasting the pattern
 * (and forgetting it once already shipped a Sentry-noisy "QuotaExceeded"
 * crash on a Safari user). One helper, one defensive pattern.
 *
 * Async-callers can ignore failures entirely; callers needing the value
 * receive `null` on read failure.
 */

/**
 * Read a string value. Returns null on missing key, parse error, or any
 * storage exception. Caller decides what to do with null (default value,
 * skip restore, etc.).
 */
export function readString(key) {
    try {
        const v = sessionStorage.getItem(key);
        return v === null || v === undefined ? null : String(v);
    } catch {
        return null;
    }
}

/**
 * Read a JSON-parsed value. Returns null on missing key, malformed JSON,
 * or storage exception. Use when the stored value is a structured object.
 */
export function readJson(key) {
    try {
        const raw = sessionStorage.getItem(key);
        if (raw === null || raw === undefined) return null;
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

/**
 * Write a string. Returns true on success, false on any failure (the
 * caller usually doesn't check — UX-only persistence is best-effort).
 */
export function writeString(key, value) {
    try {
        sessionStorage.setItem(key, String(value));
        return true;
    } catch {
        return false;
    }
}

/**
 * Write a JSON-serialised value. Returns true on success.
 */
export function writeJson(key, value) {
    try {
        sessionStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch {
        return false;
    }
}

/**
 * Remove a key. Returns true on success.
 */
export function remove(key) {
    try {
        sessionStorage.removeItem(key);
        return true;
    } catch {
        return false;
    }
}
