<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="size-3 text-gray-400 dark:text-gray-500 shrink-0"
        aria-hidden="true"
    >
        <path d="M7 7h10v10" />
        <path d="M7 17l10-10" />
    </svg>
</template>

<script>
// Tiny "↗" arrow icon. Used in clickable Overview-Tab cards as a visual signal
// that the card has a navigation action — without forcing the user to hover
// to discover the hover-ring affordance. Inline SVG (no Statamic Icon dep)
// because we want pixel-tight 12px rendering at consistent stroke weight.
export default {};
</script>
