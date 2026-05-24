<template>
    <!-- V1.2 Cross-Tab-A — shared Locale-Filter Widget.

         Hides itself entirely when `available` is empty (single-locale or
         single-site install). Otherwise renders a Statamic Dropdown with
         "All languages" + one option per indexed locale.

         Component is intentionally dumb: takes `available + current` as
         props, emits `update`. Parent owns navigation — that's how it
         stays reusable across tabs without each tab pasting route-knowledge
         into this file. -->
    <div v-if="available.length > 0" class="flex items-center gap-2">
        <label class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Locale</label>
        <Dropdown align="end">
            <template #trigger>
                <Button
                    :text="currentLabel"
                    size="sm"
                    variant="ghost"
                    icon-after="chevron-down"
                />
            </template>
            <DropdownMenu>
                <DropdownItem text="All languages" @click="select(null)" :icon="!current ? 'check' : null" />
                <DropdownSeparator />
                <DropdownItem
                    v-for="loc in available"
                    :key="loc"
                    :text="loc"
                    @click="select(loc)"
                    :icon="current === loc ? 'check' : null"
                />
            </DropdownMenu>
        </Dropdown>
    </div>
</template>

<script>
import { Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Button } from '@statamic/cms/ui';

export default {
    name: 'LocaleFilter',
    components: { Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Button },

    props: {
        // List of ISO-639-1 codes present in the persisted index (server
        // computes via LocaleFilterPresenter::availableLocales). Empty =
        // hide the widget; single-locale installs skip the filter UI.
        available: {
            type: Array,
            default: () => [],
        },
        // Currently-active filter value. Null = "all languages".
        // Type validation intentionally omitted — Vue warns when `default:
        // null` is paired with `type: String`. Null is the documented
        // "all" sentinel; constraining it to String would force callers
        // to pass '' and treat empty-string as null. Worse trade.
        current: {
            default: null,
        },
    },

    emits: ['update'],

    computed: {
        currentLabel() {
            return this.current ?? 'All languages';
        },
    },

    methods: {
        select(value) {
            if (value === this.current) return;
            this.$emit('update', value);
        },
    },
};
</script>
