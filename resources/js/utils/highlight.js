/**
 * Escape HTML entities in a string.
 */
function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Build a case-insensitive regex for matching a literal string.
 */
function anchorRegex(anchor) {
    return new RegExp(`(${anchor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'i');
}

/**
 * Highlight anchor text within a sentence context string.
 * Returns HTML-safe string with the anchor wrapped in <strong> and quoted.
 */
export function highlightedContext(sentenceContext, anchorText) {
    if (!sentenceContext || !anchorText) return '';
    const escaped = escapeHtml(sentenceContext);
    return '"' + escaped.replace(anchorRegex(anchorText), '<strong class="font-bold text-blue-600 dark:text-blue-400">$1</strong>') + '"';
}

/**
 * Highlight anchor text in a context string with a yellow mark.
 * Used across all dashboard tabs for context display.
 */
export function highlightAnchor(context, anchor) {
    if (!context || !anchor) return '';
    const escaped = escapeHtml(context);
    return escaped.replace(anchorRegex(anchor), '<strong class="font-bold text-blue-600 dark:text-blue-400">$1</strong>');
}

/**
 * Highlight a keyword in context styled like the link it would become after Apply.
 * Used by AutoLinkingTab for preview context.
 *
 * Backend may emit sentinel markers (\x01 = open, \x02 = close) around the EXACT
 * occurrence that triggered this row. We honor those when present so two preview
 * rows for matches close together highlight different positions. Falls back to
 * the first regex match if no markers are present (legacy callers).
 */
export function highlightKeyword(context, keyword) {
    if (!context) return '';

    // Sentinel-marker path: positions are precise, no regex guesswork.
    if (context.includes('\x01')) {
        const escaped = escapeHtml(context);
        return escaped
            .replace(/\x01/g, '<strong class="font-bold text-blue-600 dark:text-blue-400">')
            .replace(/\x02/g, '</strong>');
    }

    // Fallback for older payloads without markers.
    if (!keyword) return escapeHtml(context);
    const escaped = escapeHtml(context);
    return escaped.replace(anchorRegex(keyword), '<strong class="font-bold text-blue-600 dark:text-blue-400">$1</strong>');
}
