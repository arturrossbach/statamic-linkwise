<template>
    <div class="py-12 text-center">
        <div class="text-gray-400 dark:text-gray-500 mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-12 mx-auto mb-3">
                <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-600 dark:text-gray-400">{{ title }}</h3>
        <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Coming in a future update.</p>
    </div>
</template>

<script>
export default {
    props: {
        title: { type: String, default: 'Coming Soon' },
    },
};
</script>
