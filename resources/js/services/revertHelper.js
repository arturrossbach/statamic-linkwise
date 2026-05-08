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
 *   applyrule (multi-rule) ✅ each rule is now its own single-rule snapshot
 *                            (handleMultiple split, batch_id ties them
 *                            together for listing — revert is per-rule)
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

    // The original bulk must have actually finished. A null completed_at
    // means it's still in flight (or crashed mid-run before reaching the
    // markCompleted() call). Reverting an in-flight bulk would race against
    // its writes — never safe. Legacy snapshots from before this field
    // shipped don't have completed_at at all → we treat absent as completed
    // for backwards compatibility (those are the ones the user already saw
    // working pre-change).
    if (snapshot.completed_at === null) return false;

    const kind = snapshot.kind;
    if (kind === 'bulkunlink') return false;
    // Note: multi-rule "Apply Selected" no longer creates a single
    // mode='multi-rule' snapshot — handleMultiple now splits into N
    // single-rule snapshots. Each is revertable by the standard applyrule
    // path. Old snapshots with mode='multi-rule' (pre-split) stay
    // unrevertable via the legacy reason in nonReversibleReason.
    if (kind === 'applyrule' && snapshot.summary?.mode === 'multi-rule') return false;

    // Need items to build the inverse payload
    if (!Array.isArray(snapshot.items) || snapshot.items.length === 0) return false;

    // detailunlink-revert is only meaningful for items whose matched_url is
    // an internal entry reference — external URLs (https://…) need a target
    // entry to re-link through inbound/outbound-insert, which we don't have.
    // If at least one item is a statamic:// link we still surface the button
    // (filtered to those at apply-time); the UI clarifies the partial scope.
    // detailunlink revert: re-add the link mark on the original anchor.
    // Routes via outbound-insert which now supports BOTH internal entry
    // refs (target_entry_id) AND external URLs (href). The only hard
    // requirement is anchor_text — without it we don't know what plain-
    // text run to wrap. Older snapshots from before anchor recording
    // shipped have no anchor_text, so they stay unrevertable.
    if (kind === 'detailunlink') {
        return snapshot.items.some(i => !! i?.anchor_text && !! i?.matched_url);
    }

    // URL-Changer mixes two operation shapes that revert through different
    // pipelines:
    //   - replace items (new_url is a real URL): symmetric swap via url-changer apply
    //   - unlink items (new_url=UNLINK_SENTINEL): asymmetric — needs the link
    //     mark RE-ADDED on the original anchor; we route those through
    //     outbound-insert (which accepts arbitrary href, so internal AND
    //     external URLs are both fine — we just need anchor_text).
    //   Mixed snapshots are reversible if any item is swappable OR any item
    //   is a re-link candidate.
    if (kind === 'urlchanger') {
        return snapshot.items.some(i => isUrlchangerItemReversible(i));
    }

    return true;
}

function isUrlchangerItemReversible(item) {
    if (! item) return false;
    if (item.new_url && item.new_url !== UNLINK_SENTINEL) return true; // swap path
    // Unlink revert: re-link via outbound-insert. Needs anchor_text + a
    // matched_url (internal or external) — outbound-insert accepts both
    // via the href fallback, so external URLs are now first-class too.
    if (item.new_url === UNLINK_SENTINEL && item.matched_url && item.anchor_text) {
        return true;
    }
    return false;
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
    if (snapshot.completed_at === null) {
        return 'This operation is still in progress. Wait for the bulk to finish before reverting — the activity-log entry will become revertable as soon as the run completes.';
    }
    if (snapshot.kind === 'bulkunlink') {
        return 'Bulk-unlink of broken links is not reversible — the URLs were broken at the time of removal. Re-linking them would re-introduce broken references.';
    }
    if (snapshot.kind === 'detailunlink') {
        // Reachable only when no items have anchor_text — older snapshots
        // recorded before anchor capture shipped, so we don't know which
        // plain-text run to wrap on re-link.
        return 'This unlink snapshot was recorded before per-item anchor capture shipped — Linkwise can\'t reconstruct which text to re-link. Newer unlinks (created from now on) are revertable.';
    }
    if (snapshot.kind === 'applyrule' && snapshot.summary?.mode === 'multi-rule') {
        return 'This is a legacy multi-rule snapshot from before the per-rule split shipped — items list rules, not entries, so Linkwise can\'t target an inverse bulk. New "Apply Selected" runs create one snapshot per rule, each individually revertable.';
    }
    if (snapshot.kind === 'urlchanger') {
        // Only legacy unlink snapshots without anchor_text fall here now —
        // external-URL unlinks ARE revertable via the href path.
        const items = Array.isArray(snapshot.items) ? snapshot.items : [];
        const hasUnlinkWithoutAnchor = items.some(i =>
            i?.new_url === UNLINK_SENTINEL && ! i?.anchor_text
        );
        if (hasUnlinkWithoutAnchor) {
            return 'This URL-Changer "Unlink" snapshot was recorded before per-item anchor capture shipped — Linkwise can\'t reconstruct which text to re-link. Snapshots created from now on are revertable.';
        }
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

    // Reuse the post-bulk hashes from the original snapshot as expected
    // pre-revert state. SafeEntrySaver will 409-skip any entry whose live
    // hash no longer matches — meaning the user edited it since the bulk
    // and we'd otherwise silently overwrite their changes.
    const entryHashes = snapshot.post_hashes || {};

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
                entry_hashes: entryHashes,
                source_mode: 'outbound', // we're removing outbound links from each affected entry
                entry_title: 'Revert: rule "' + (summary.rule_keyword || '?') + '"',
                reverts: snapshot.id,
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
                entry_hashes: entryHashes,
                // The source-mode reflects what the original op did, mirrored:
                // inbound-insert added inbound links to a target → revert removes
                // those inbound links. Banner reflects this.
                source_mode: snapshot.kind === 'inboundinsert' ? 'inbound' : 'outbound',
                entry_title: 'Revert: ' + (summary.entry_title || 'bulk insert'),
                reverts: snapshot.id,
            },
            kindLabel: 'unlink',
        };
    }

    if (snapshot.kind === 'detailunlink') {
        // Items = {entry_id, matched_url, anchor_text}.
        // Re-insert each via inbound/outbound-insert. The endpoint accepts
        // BOTH internal entry refs AND arbitrary URLs — for internal we
        // pass target_entry_id (resolves into statamic://entry::ID server-
        // side), for external we pass href verbatim. Both call into the
        // same BardLinkInserter::insertLinkIntoEntryWithHref path.
        // The only requirement is anchor_text — without it we don't know
        // what plain-text run to wrap.
        const reLinkable = items.filter(i => i?.matched_url && i?.anchor_text);
        const insertions = reLinkable.map(i => {
            const isInternal = typeof i.matched_url === 'string'
                && i.matched_url.startsWith('statamic://entry::');
            const base = {
                source_entry_id: i.entry_id,
                anchor_text: i.anchor_text || '',
            };
            return isInternal
                ? { ...base, target_entry_id: i.matched_url.replace('statamic://entry::', '') }
                : { ...base, href: i.matched_url };
        });
        const isInbound = (summary.source_mode || 'inbound') === 'inbound';
        return {
            url: isInbound ? endpoints.inboundInsert : endpoints.outboundInsert,
            payload: {
                insertions,
                entry_hashes: entryHashes,
                source_mode: summary.source_mode || 'inbound',
                entry_title: 'Revert: ' + (summary.entry_title || 'detail unlink'),
                reverts: snapshot.id,
            },
            kindLabel: 're-link',
        };
    }

    if (snapshot.kind === 'urlchanger') {
        // Two buckets — see isUrlchangerItemReversible:
        //   swappable: had a real new_url → revert via url-changer apply (swap)
        //   re-link:   new_url=sentinel + internal matched_url + anchor_text
        //              → revert via outbound-insert (re-add the link mark)
        // Items not in either bucket (external sentinel, missing anchor) are
        // dropped silently; the per-item filter below mirrors isReversible
        // so we don't ship "Revert" buttons that would do nothing.
        //
        // If both buckets are non-empty we prefer the swap path and let the
        // re-link bucket lose — mixed Replace+Unlink in one bulk is rare and
        // routing to two endpoints would need a serial-fanout that V1's
        // single-job-per-revert UX doesn't support. The user can revert
        // again to pick up the other bucket if they hit this.
        const swappable = items.filter(i =>
            i.entry_id && i.matched_url && i.new_url && i.new_url !== UNLINK_SENTINEL
        );
        const reLink = items.filter(i =>
            i.new_url === UNLINK_SENTINEL
            && i.entry_id
            && i.matched_url
            && i.anchor_text
        );

        if (swappable.length > 0) {
            const replacements = swappable.map(i => ({
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
                    entry_hashes: entryHashes,
                    mode: 'exact',
                    action: 'apply',
                    search: '',
                    reverts: snapshot.id,
                },
                kindLabel: 'replace',
            };
        }

        if (reLink.length > 0) {
            const insertions = reLink.map(i => {
                const isInternal = typeof i.matched_url === 'string'
                    && i.matched_url.startsWith('statamic://entry::');
                const base = {
                    source_entry_id: i.entry_id,
                    anchor_text: i.anchor_text,
                };
                return isInternal
                    ? { ...base, target_entry_id: i.matched_url.replace('statamic://entry::', '') }
                    : { ...base, href: i.matched_url };
            });
            return {
                url: endpoints.outboundInsert,
                payload: {
                    insertions,
                    entry_hashes: entryHashes,
                    source_mode: 'outbound',
                    entry_title: 'Revert: URL-Changer unlink',
                    reverts: snapshot.id,
                },
                kindLabel: 're-link',
            };
        }

        return null;
    }

    return null;
}
