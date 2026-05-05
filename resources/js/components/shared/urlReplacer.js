/**
 * Shared URL replacement logic.
 * Used by UrlChangerTab to apply URL changes.
 */

/**
 * Sentinel value for the new_url field that signals "remove this link entirely,
 * keep the text". Must match `UrlHelper::UNLINK` on the PHP side.
 */
export const UNLINK_SENTINEL = '__LINKWISE_UNLINK__';

export async function applyUrlReplacements(applyUrl, search, replacements, entryHashes = {}, extraParams = {}) {
    const response = await fetch(applyUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ search, replacements, entry_hashes: entryHashes, ...extraParams }),
    });

    if (!response.ok) {
        const errorData = await response.json().catch(() => null);
        const err = new Error(errorData?.message || 'Replace failed.');
        err.status = response.status;
        err.conflict = response.status === 409;
        err.entryId = errorData?.entry_id || null;
        throw err;
    }

    return await response.json();
}
