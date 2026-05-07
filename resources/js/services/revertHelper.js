/**
 * Build the inverse-bulk payload for a recorded activity-log snapshot.
 *
 * The trick: we don't introduce new write paths. Each revert dispatches the
 * existing bulk endpoint (detail-unlink-async, inbound/outbound-insert,
 * url-changer/apply-async) with the snapshot's items mapped to its inverse.
 *
 * That gives us, for free:
 *   - JobLock concurrency guard (the revert is itself a heavy bulk)
 *   - SafeEntrySaver hash-check per entry (skips entries edited since the
 *     original bulk — preserves their post-bulk edits)
 *   - Heavy-bulk banner with progress, cancel, navigate-away
 *   - A NEW snapshot recorded for the revert itself — the activity-log
 *     becomes a chain (you can revert a revert; the trail is durable)
 *
 * Reversibility matrix (snapshot.kind → reversible?):
 *   applyrule (single)     ✅ reverse via detail-unlink-async
 *   inboundinsert          ✅ reverse via detail-unlink-async
 *   outboundinsert         ✅ reverse via detail-unlink-async
 *   urlchanger             ✅ reverse via url-changer/apply-async (urls swapped)
 *   detailunlink           ❌ V1 — re-insert is fragile (anchor must still
 *                              exist in the entry text after intervening edits)
 *   bulkunlink             ❌ never — broken-link removals shouldn't re-link
 *   applyrule (multi-rule) ❌ V1 — no per-entry items, only rule list
 *
 * The UI layer reads `isReversible()` to enable/disable the Revert button
 * and `buildRevertRequest()` to obtain the {url, payload, kindLabel} tuple
 * to POST.
 */

const UNLINK_SENTINEL = '__LINKWISE_UNLINK__'; // matches Arturrossbach\Linkwise\Support\UrlHelper::UNLINK

/**
 * @param {object} snapshot — full snapshot detail (.snapshot field of activity-detail JSON)
 * @returns {boolean}
 */
export function isReversible(snapshot) {
    if (!snapshot || !snapshot.kind) return false;
    if (snapshot.reverted_at) return false; // already reverted

    const kind = snapshot.kind;
    if (kind === 'bulkunlink') return false;
    if (kind === 'detailunlink') return false; // V1 doesn't re-link
    if (kind === 'applyrule' && snapshot.summary?.mode === 'multi-rule') return false;

    // Need items to build the inverse payload
    if (!Array.isArray(snapshot.items) || snapshot.items.length === 0) return false;

    return true;
}

/**
 * Human-readable reason why a snapshot CAN'T be reverted. Surfaced in the UI
 * tooltip so users understand why the button is disabled.
 *
 * @param {object} snapshot
 * @returns {string|null}
 */
export function nonReversibleReason(snapshot) {
    if (!snapshot) return 'Snapshot not loaded';
    if (snapshot.reverted_at) return 'This operation was already reverted.';
    if (snapshot.kind === 'bulkunlink') {
        return 'Bulk-unlink of broken links is not reversible — the URLs were broken at the time of removal. Re-linking them would re-introduce broken references.';
    }
    if (snapshot.kind === 'detailunlink') {
        return 'Re-linking after a Detail-modal unlink is not yet supported in V1 — the anchor text needs to still exist in the entry, which can no longer be guaranteed once the entry has been edited.';
    }
    if (snapshot.kind === 'applyrule' && snapshot.summary?.mode === 'multi-rule') {
        return 'Multi-rule applies are not yet revertable in V1 — use the "Find these in URL Changer" link or revert each rule individually from a single-rule activity entry.';
    }
    if (!Array.isArray(snapshot.items) || snapshot.items.length === 0) {
        return 'This snapshot has no per-item operation data — it was recorded by an older version of Linkwise.';
    }
    return null;
}

/**
 * Build the revert request: which endpoint to POST and what payload.
 *
 * @param {object} snapshot
 * @param {object} endpoints — from activity-page props ({detailUnlink, inboundInsert, outboundInsert, urlChangerApply})
 * @returns {{url: string, payload: object, kindLabel: string} | null}
 */
export function buildRevertRequest(snapshot, endpoints) {
    if (!isReversible(snapshot)) return null;
    const items = snapshot.items;
    const summary = snapshot.summary || {};

    if (snapshot.kind === 'applyrule') {
        // Single-rule apply: each item = {entry_id, anchor_text, url}.
        // Revert = remove that specific link from each entry.
        const replacements = items
            .filter(i => i.entry_id && i.url)
            .map(i => ({
                entry_id: i.entry_id,
                matched_url: i.url,
                anchor_text: i.anchor_text || '',
                occurrence_index: 0,
                new_url: UNLINK_SENTINEL,
            }));
        return {
            url: endpoints.detailUnlink,
            payload: {
                replacements,
                source_mode: 'outbound', // we're removing outbound links from each affected entry
                entry_title: 'Revert: rule "' + (summary.rule_keyword || '?') + '"',
            },
            kindLabel: 'unlink',
        };
    }

    if (snapshot.kind === 'inboundinsert' || snapshot.kind === 'outboundinsert') {
        // Items = {source_entry_id, target_entry_id, anchor_text}
        // Revert = unlink each (source, target) pair.
        const replacements = items
            .filter(i => i.source_entry_id && i.target_entry_id)
            .map(i => ({
                entry_id: i.source_entry_id,
                matched_url: 'statamic://entry::' + i.target_entry_id,
                anchor_text: i.anchor_text || '',
                occurrence_index: 0,
                new_url: UNLINK_SENTINEL,
            }));
        return {
            url: endpoints.detailUnlink,
            payload: {
                replacements,
                // The source-mode reflects what the original op did, mirrored:
                // inbound-insert added inbound links to a target → revert removes
                // those inbound links. Banner reflects this.
                source_mode: snapshot.kind === 'inboundinsert' ? 'inbound' : 'outbound',
                entry_title: 'Revert: ' + (summary.entry_title || 'bulk insert'),
            },
            kindLabel: 'unlink',
        };
    }

    if (snapshot.kind === 'urlchanger') {
        // Items = {entry_id, matched_url, new_url}
        // Revert = swap matched_url ↔ new_url and re-apply.
        const replacements = items
            .filter(i => i.entry_id && i.matched_url && i.new_url)
            .map(i => ({
                entry_id: i.entry_id,
                matched_url: i.new_url,    // swapped
                new_url: i.matched_url,    // swapped
                occurrence_index: 0,
                field: '',
                field_type: '',
            }));
        return {
            url: endpoints.urlChangerApply,
            payload: {
                replacements,
                mode: 'exact', // we know the exact URLs — no domain inference
                action: 'apply',
                search: '', // not needed when we send exact matched_url per item
            },
            kindLabel: 'replace',
        };
    }

    return null;
}
