import LinkwiseFieldtype from './components/LinkwiseFieldtype.vue';
import { installErrorReporter } from './utils/errorReporter.js';

// Inertia Pages — one per tab
import OverviewPage from './components/pages/OverviewPage.vue';
import LinksPage from './components/pages/LinksPage.vue';
import BrokenLinksPage from './components/pages/BrokenLinksPage.vue';
import DomainsPage from './components/pages/DomainsPage.vue';
import AutoLinkPage from './components/pages/AutoLinkPage.vue';
import KeywordsPage from './components/pages/KeywordsPage.vue';
import UrlChangerPage from './components/pages/UrlChangerPage.vue';

Statamic.booting(() => {
    Statamic.$components.register('linkwise-fieldtype', LinkwiseFieldtype);

    // Register Inertia pages
    Statamic.$inertia.register('linkwise::Overview', OverviewPage);
    Statamic.$inertia.register('linkwise::Links', LinksPage);
    Statamic.$inertia.register('linkwise::BrokenLinks', BrokenLinksPage);
    Statamic.$inertia.register('linkwise::Domains', DomainsPage);
    Statamic.$inertia.register('linkwise::AutoLink', AutoLinkPage);
    Statamic.$inertia.register('linkwise::Keywords', KeywordsPage);
    Statamic.$inertia.register('linkwise::UrlChanger', UrlChangerPage);

    // Frontend error reporter — pipes Vue render errors, runtime JS errors,
    // and unhandled-promise-rejections to storage/linkwise/frontend-errors.log
    // so they end up in the support debug-export ZIP. Statamic.$app is the
    // Vue 3 application instance — see vendor/statamic/cms/resources/js/
    // bootstrap/statamic.js for the assignment.
    installErrorReporter(Statamic.$app);
});
