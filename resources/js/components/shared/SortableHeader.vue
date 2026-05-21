<template>
    <th :scope="scope" :class="thClass" :aria-sort="ariaSort">
        <div class="inline-flex items-center gap-1" :class="innerClass">
            <Button
                v-if="sortable"
                :text="label"
                :icon-append="sortIcon"
                size="sm"
                variant="ghost"
                class="-mt-2 -mb-1 -ms-3 text-sm! font-medium! text-gray-900! dark:text-gray-400!"
                @click.prevent="$emit('sort')"
                @keydown.enter.prevent="$emit('sort')"
                @keydown.space.prevent="$emit('sort')"
            />
            <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-400">{{ label }}</span>
            <slot />
        </div>
    </th>
</template>

<script>
import { Button } from '@statamic/cms/ui';

export default {
    components: { Button },

    props: {
        label: { type: String, required: true },
        sortable: { type: Boolean, default: true },
        active: { type: Boolean, default: false },
        direction: { type: String, default: 'asc' },
        align: { type: String, default: 'left' }, // 'left' | 'center' | 'right'
        scope: { type: String, default: 'col' },
    },

    emits: ['sort'],

    computed: {
        sortIcon() {
            if (!this.sortable || !this.active) return null;
            return this.direction === 'asc' ? 'sort-asc' : 'sort-desc';
        },

        ariaSort() {
            if (!this.sortable) return null;
            if (!this.active) return 'none';
            return this.direction === 'asc' ? 'ascending' : 'descending';
        },

        thClass() {
            return {
                'text-left': this.align === 'left',
                'text-center': this.align === 'center',
                'text-right': this.align === 'right',
            };
        },

        innerClass() {
            return this.align === 'center' ? 'justify-center w-full' : (this.align === 'right' ? 'justify-end w-full' : '');
        },
    },
};
</script>
