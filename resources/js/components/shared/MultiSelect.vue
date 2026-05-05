<template>
    <div class="relative inline-block" ref="root">
        <!-- Trigger: real button = natively focusable + keyboard accessible -->
        <button
            ref="trigger"
            type="button"
            @click="open = !open"
            @keydown.down.prevent="openDropdown"
            @keydown.up.prevent="openDropdown"
            @keydown.enter.prevent="openDropdown"
            @keydown.space.prevent="openDropdown"
            @keydown.escape.prevent="closeDropdown"
            :aria-expanded="open"
            aria-haspopup="listbox"
            class="text-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-3 py-1.5 flex items-center gap-1.5 cursor-pointer whitespace-nowrap hover:border-gray-400 dark:hover:border-gray-600 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 focus-visible:outline-none"
        >
            <span>{{ displayLabel }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4 text-gray-400 -mr-0.5">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div
            v-show="open"
            ref="dropdown"
            role="listbox"
            aria-multiselectable="true"
            class="absolute z-50 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 min-w-full max-h-60 overflow-y-auto"
        >
            <div
                v-for="(opt, idx) in normalizedOptions"
                :key="opt.value"
                role="option"
                :aria-checked="isSelected(opt.value)"
                @click.stop="toggle(opt.value)"
                @mouseenter="activeIdx = idx"
                :class="activeIdx === idx ? 'bg-gray-100 dark:bg-gray-700' : ''"
                class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer select-none whitespace-nowrap"
            >
                <input type="checkbox" :checked="isSelected(opt.value)" class="rounded shrink-0 pointer-events-none" tabindex="-1" aria-hidden="true" />
                <span class="text-gray-800 dark:text-gray-200">{{ opt.label }}</span>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        // Defaults to [] so a caller that forgets to seed the field doesn't
        // crash the dropdown's `.length` calls. Vue does not warn on the
        // missing prop because `default` makes it optional.
        modelValue: { type: Array, default: () => [] },
        options: { type: Array, required: true },
        label: { type: String, default: 'Select' },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            open: false,
            activeIdx: -1,
        };
    },

    computed: {
        normalizedOptions() {
            return this.options.map(o => typeof o === 'string' ? { value: o, label: o } : o);
        },
        displayLabel() {
            const count = this.modelValue.length;
            const total = this.normalizedOptions.length;
            // No filter applied (all or nothing selected) — show the placeholder
            // label since "all" and "none" mean the same thing for filter UX.
            if (count === 0 || count === total) return this.label;
            // Partial selection — show actual selected names so the user can read
            // their filter at a glance instead of "Status · 2 of 5".
            const selected = this.normalizedOptions
                .filter(o => this.modelValue.includes(o.value))
                .map(o => o.label);
            if (count <= 2) return selected.join(', ');
            // 3+ selected — first two plus a count of the rest.
            return `${selected.slice(0, 2).join(', ')} +${count - 2}`;
        },
    },

    watch: {
        open(isOpen) {
            if (isOpen) {
                this.activeIdx = 0;
                document.addEventListener('click', this.onOutsideClick);
                // Keep focus on button — keyboard events handled there
                this.$nextTick(() => {
                    this.$refs.trigger?.addEventListener('keydown', this.onDropdownKeydown);
                });
            } else {
                this.activeIdx = -1;
                document.removeEventListener('click', this.onOutsideClick);
                this.$refs.trigger?.removeEventListener('keydown', this.onDropdownKeydown);
            }
        },
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onOutsideClick);
        this.$refs.trigger?.removeEventListener('keydown', this.onDropdownKeydown);
    },

    methods: {
        isSelected(value) {
            return this.modelValue.includes(value);
        },

        toggle(value) {
            const arr = [...this.modelValue];
            const idx = arr.indexOf(value);
            if (idx > -1) arr.splice(idx, 1);
            else arr.push(value);
            this.$emit('update:modelValue', arr);
        },

        openDropdown() {
            this.open = true;
        },

        closeDropdown() {
            this.open = false;
            this.$refs.trigger?.focus();
        },

        onDropdownKeydown(e) {
            if (!this.open) return;

            const opts = this.normalizedOptions;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    e.stopPropagation();
                    this.activeIdx = Math.min(this.activeIdx + 1, opts.length - 1);
                    this.scrollToActive();
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    e.stopPropagation();
                    this.activeIdx = Math.max(this.activeIdx - 1, 0);
                    this.scrollToActive();
                    break;
                case ' ':
                case 'Enter':
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.activeIdx >= 0) this.toggle(opts[this.activeIdx].value);
                    break;
                case 'Escape':
                    e.preventDefault();
                    e.stopPropagation();
                    this.closeDropdown();
                    break;
                case 'Home':
                    e.preventDefault();
                    this.activeIdx = 0;
                    this.scrollToActive();
                    break;
                case 'End':
                    e.preventDefault();
                    this.activeIdx = opts.length - 1;
                    this.scrollToActive();
                    break;
                case 'Tab':
                    this.open = false;
                    break;
            }
        },

        scrollToActive() {
            this.$nextTick(() => {
                this.$refs.dropdown?.children[this.activeIdx]?.scrollIntoView({ block: 'nearest' });
            });
        },

        onOutsideClick(e) {
            if (!this.$refs.root?.contains(e.target)) {
                this.open = false;
            }
        },
    },
};
</script>
