/**
 * Linkwise toast helpers — wrap Statamic.$toast with longer durations for
 * errors and warnings so the user actually has time to read them.
 *
 * Statamic's default is 3500 ms, which is fine for "Saved!" but too short
 * for multi-clause error messages like
 *   "Search failed: Entry was modified by another editor."
 * Errors are sticky-ish (12 s) so the user can read + decide; warnings
 * mid-length (6 s); success/info default (Statamic 3.5 s).
 *
 * Usage: import { errorToast, warnToast } from '../utils/toast.js';
 *        errorToast('Apply failed: ' + reason);
 */

const ERROR_DURATION_MS = 12000;
const WARN_DURATION_MS = 6000;

export function errorToast(message) {
    return Statamic.$toast.error(message, { duration: ERROR_DURATION_MS });
}

export function warnToast(message) {
    return Statamic.$toast.info(message, { duration: WARN_DURATION_MS });
}
