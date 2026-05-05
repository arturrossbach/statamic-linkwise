/**
 * Build a resourceMeta object compatible with Statamic's Pagination component
 * for client-side paginated arrays.
 */
export function buildPaginationMeta(total, currentPage, perPage) {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const from = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
    const to = Math.min(currentPage * perPage, total);
    return { current_page: currentPage, last_page: lastPage, total, from, to, per_page: perPage };
}

/**
 * Slice an array into the current page window.
 */
export function paginateItems(items, currentPage, perPage) {
    const start = (currentPage - 1) * perPage;
    return items.slice(start, start + perPage);
}
