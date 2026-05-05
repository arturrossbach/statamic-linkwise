/**
 * Shared URL validation for Linkwise replace flows.
 *
 * Used by BrokenLinksTab (per-row replace input) and UrlChangerTab
 * (bulk-replace + per-row New URL inputs). Single source of truth so the
 * accept/reject criteria don't drift between tabs.
 *
 * A replacement URL is considered valid when:
 *   1. Contains no whitespace (URL constructor is too lenient with spaces —
 *      "www.fose.de - hallo" would otherwise pass on some engines).
 *   2. Parses via the URL constructor.
 *   3. Protocol is http(s), mailto: or tel: (rejects javascript: etc.).
 *   4. For http(s): hostname is a plausible DNS name (alnum + dots/dashes,
 *      contains at least one dot — rejects "localhost", "fose").
 *
 * Empty input returns false — call sites typically gate on
 * `value.trim() !== '' && !isValidReplacementUrl(value)` to render the
 * "invalid" hint, so they don't flag an untouched input.
 */
export function isValidReplacementUrl(value) {
    const v = (value || '').trim();
    if (!v || /\s/.test(v)) return false;
    let url;
    try {
        url = new URL(v);
    } catch {
        return false;
    }
    if (!['http:', 'https:', 'mailto:', 'tel:'].includes(url.protocol)) return false;
    if (['http:', 'https:'].includes(url.protocol)) {
        if (!/^[a-z0-9.-]+$/i.test(url.hostname)) return false;
        if (!url.hostname.includes('.')) return false;
    }
    return true;
}
