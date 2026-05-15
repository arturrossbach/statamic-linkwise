/**
 * Shared "form has unsaved changes" detector for Linkwise edit-modes.
 *
 * Extracted from AutoLinkingTab.vue's `formDirty` computed during
 * REV-FE-01 (Sprint 5 PR 2 Phase B). Future Linkwise edit-modal
 * surfaces (URL Changer rules, Target Keyword overrides) can reuse
 * the same dirty-tracking semantics without duplicating the field-by-
 * field comparison.
 *
 * Contract:
 *   - `snapshot` is the form-state captured when edit started.
 *   - `current` is the live form-state (typically `newRule`).
 *   - Returns true when ANY snapshot key differs from current,
 *     OR when the secondary mode flag (linkMode) differs from its
 *     own snapshot. The mode-pair is optional — when both modes are
 *     undefined the comparison degrades to a pure field-diff.
 *   - Returns false when snapshot is missing (= not in edit mode).
 *
 * The "snapshot is missing → false" path is critical: it gates the
 * "Discard / Save" toolbar from showing on freshly-opened forms
 * before the user typed anything.
 */
export function isFormDirty(snapshot, current, snapshotMode = undefined, currentMode = undefined) {
    if (!snapshot) return false;
    for (const key of Object.keys(snapshot)) {
        if (snapshot[key] !== current[key]) return true;
    }
    if (snapshotMode !== undefined && snapshotMode !== currentMode) return true;
    return false;
}
