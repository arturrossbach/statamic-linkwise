<template>
    <!-- V1.2 Cross-Tab-A — shared Locale-Filter Widget.

         Native <select> intentionally: matches the existing
         CollectionFilter pattern (LinksReportTab) one-to-one so editors
         see a single visual mental-model for "filter by X" across the
         CP. Avoids the Statamic Dropdown-trigger-as-ghost-button look
         which users mistake for static text (User-Smoke 2026-05-25).

         Hides itself entirely when `available` is empty (single-locale
         or single-site install). Empty string = "all languages" sentinel
         on the wire because <select> can't `v-model` null cleanly. -->
    <select
        v-if="available.length > 0"
        :value="current ?? ''"
        @change="select($event.target.value === '' ? null : $event.target.value)"
        :aria-label="ariaLabel"
        class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md px-2 py-1.5"
    >
        <option value="">All languages</option>
        <option v-for="loc in available" :key="loc" :value="loc">{{ loc }}</option>
    </select>
</template>

<script>
export default {
    name: 'LocaleFilter',

    props: {
        // List of ISO-639-1 codes present in the persisted index (server
        // computes via LocaleFilterPresenter::availableLocales). Empty =
        // hide the widget; single-locale installs skip the filter UI.
        available: {
            type: Array,
            default: () => [],
        },
        // Currently-active filter value. Null = "all languages".
        // Type validation intentionally omitted — Vue warns when
        // `default: null` is paired with `type: String`. Null is the
        // documented "all" sentinel.
        current: {
            default: null,
        },
        ariaLabel: {
            type: String,
            default: 'Filter by locale',
        },
    },

    emits: ['update'],

    methods: {
        select(value) {
            if (value === this.current) return;
            this.$emit('update', value);
        },
    },
};
</script>
