import { defineConfig } from 'vitepress';

export default defineConfig({
    title: 'Linkwise',
    description: 'The internal linking assistant for Statamic 6. Suggestion engine, broken-link finder, domain-level rel governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your site. Runs entirely on your server.',
    lang: 'en-US',
    cleanUrls: true,
    lastUpdated: true,

    // Internal engineering docs + unreviewed marketing copy live under docs/
    // but must never be built into the public site.
    srcExclude: [
        'ARCHITECTURE_REVIEW.md',
        'CODE_REVIEW_*.md',
        'SISTER_AUDIT_*.md',
        'MULTISITE_AUDIT.md',
        'MARKETPLACE_LISTING.md',
        'internal/**',
    ],

    head: [
        ['meta', { name: 'theme-color', content: '#10b981' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Linkwise — Internal linking assistant for Statamic' }],
        ['meta', { property: 'og:description', content: 'Suggestion engine, broken-link finder, rel-attribute governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your Statamic site.' }],
    ],

    themeConfig: {
        siteTitle: 'Linkwise',

        nav: [
            { text: 'Guide', link: '/getting-started/installation' },
            { text: 'Reference', link: '/reference/config-options' },
            { text: 'FAQ', link: '/faq' },
            {
                text: 'Resources',
                items: [
                    { text: 'Changelog', link: 'https://github.com/arturrossbach/statamic-linkwise/blob/master/CHANGELOG.md' },
                    { text: 'Editions & License', link: '/getting-started/editions' },
                    { text: 'Statamic Marketplace', link: 'https://statamic.com/addons' },
                ],
            },
        ],

        sidebar: {
            '/': [
                {
                    text: 'Getting Started',
                    items: [
                        { text: 'Editions', link: '/getting-started/editions' },
                        { text: 'Installation', link: '/getting-started/installation' },
                    ],
                },
                {
                    text: 'Usage',
                    items: [
                        { text: 'Overview', link: '/usage/overview' },
                        { text: 'Links Report', link: '/usage/links-report' },
                        { text: 'Custom Keywords', link: '/usage/custom-keywords' },
                        { text: 'Auto-Linking', link: '/usage/auto-linking' },
                        { text: 'Broken Links', link: '/usage/broken-links' },
                        { text: 'URL Changer', link: '/usage/url-changer' },
                        { text: 'Domains', link: '/usage/domains' },
                        { text: 'Activity Log', link: '/usage/activity-log' },
                        { text: 'Multilingual', link: '/usage/multilingual' },
                        { text: 'Configuration', link: '/usage/configuration' },
                        { text: 'Permissions', link: '/usage/permissions' },
                    ],
                },
                {
                    text: 'Reference',
                    items: [
                        { text: 'Configuration Options', link: '/reference/config-options' },
                        { text: 'Commands', link: '/reference/commands' },
                    ],
                },
                {
                    text: 'Help',
                    items: [
                        { text: 'FAQ', link: '/faq' },
                        { text: 'Troubleshooting', link: '/troubleshooting' },
                    ],
                },
            ],
        },

        socialLinks: [
            { icon: 'github', link: 'https://github.com/arturrossbach/statamic-linkwise' },
        ],

        footer: {
            message: 'Released under a commercial license.',
            copyright: 'Copyright © 2026 Artur Rossbach',
        },

        editLink: {
            pattern: 'https://github.com/arturrossbach/statamic-linkwise/edit/master/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },
    },
});
