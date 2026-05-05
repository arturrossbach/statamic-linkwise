import { defineConfig } from 'vitepress';

export default defineConfig({
    title: 'Linkwise',
    description: 'The internal linking assistant for Statamic 6. Suggestion engine, broken-link finder, domain-level rel governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your site. Runs entirely on your server.',
    lang: 'en-US',
    cleanUrls: true,
    lastUpdated: true,

    head: [
        ['meta', { name: 'theme-color', content: '#10b981' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Linkwise — Internal linking assistant for Statamic' }],
        ['meta', { property: 'og:description', content: 'Suggestion engine, broken-link finder, rel-attribute governance, and full-site URL rewrite — across every Bard, Replicator, and Markdown field on your Statamic site.' }],
    ],

    themeConfig: {
        siteTitle: 'Linkwise',

        nav: [
            { text: 'Guide', link: '/guide/installation', activeMatch: '/guide/' },
            {
                text: 'v1.0',
                items: [
                    { text: 'Changelog', link: 'https://github.com/arturrossbach-cloud/statamic-linkwise/blob/master/CHANGELOG.md' },
                    { text: 'License', link: 'https://github.com/arturrossbach-cloud/statamic-linkwise/blob/master/LICENSE.md' },
                    { text: 'Statamic Marketplace', link: 'https://statamic.com/addons' },
                ],
            },
        ],

        sidebar: {
            '/guide/': [
                {
                    text: 'Introduction',
                    items: [
                        { text: 'What is Linkwise?', link: '/guide/' },
                        { text: 'Installation', link: '/guide/installation' },
                        { text: 'Configuration', link: '/guide/configuration' },
                    ],
                },
            ],
        },

        socialLinks: [
            { icon: 'github', link: 'https://github.com/arturrossbach-cloud/statamic-linkwise' },
        ],

        footer: {
            message: 'Released under a commercial license.',
            copyright: 'Copyright © 2026 Inkline',
        },

        editLink: {
            pattern: 'https://github.com/arturrossbach-cloud/statamic-linkwise/edit/master/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },
    },
});
