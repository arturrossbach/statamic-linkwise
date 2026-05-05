/**
 * Shared sortable table mixin for Vue Options API.
 * Components using this must have `sortField` and `sortDirection` in data().
 * Override `defaultSortDirection(field)` to customize per-field defaults.
 */
export const sortableMixin = {
    methods: {
        toggleSort(field) {
            if (this.sortField === field) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortField = field;
                this.sortDirection = this.defaultSortDirection ? this.defaultSortDirection(field) : 'asc';
            }
        },
    },
};
