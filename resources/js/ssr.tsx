import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';
import { route } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/*
 * The server half of the app, rendered by Node before the browser sees it.
 *
 * Without this the document nginx serves is an empty <div id="app">: every
 * word on the marketing pages is written by React once the bundle has loaded.
 * Googlebot will run that eventually, but on a second pass days later; Bing,
 * Yandex, and the crawlers behind WhatsApp and Telegram link previews do not
 * run it at all. For a product sold to WhatsApp resellers, a link that unfurls
 * to nothing is the expensive half of that.
 *
 * Everything here is deliberately a mirror of app.tsx. `title` and `resolve`
 * in particular have to agree with it exactly — React compares the server's
 * HTML against the client's first render, and a title built with a different
 * dash is a mismatch that throws away the server output it just paid for.
 */
createServer((page) => {
    /*
     * `route()` is a browser global in the client build, published by Blade's
     * @routes tag. Node has no window for that tag to write to, so it is bound
     * here instead, from the route list share() sends with every page.
     *
     * Set before createInertiaApp rather than inside setup(): components call
     * route() while rendering, and by then it has to already be there.
     */
    const ziggy = (page.props as { ziggy?: Record<string, unknown> }).ziggy;

    // Cast rather than typed wrapper: Ziggy overloads route() four ways over
    // whether a name is given, and a single forwarding signature satisfies
    // none of them. global.d.ts already declares the global's real shape, so
    // callers still get the checked version.
    globalThis.route = ((name?: string, params?: unknown, absolute?: boolean) =>
        route(
            name as never,
            params as never,
            absolute,
            ziggy as never,
        )) as typeof globalThis.route;

    return createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => `${title} - ${appName}`,

        // Eager, unlike the client's lazy glob: there is no network here to
        // stagger imports over, and every page is bundled into this one file
        // anyway. Resolved by hand rather than through resolvePageComponent,
        // which expects the lazy form and types its map as promises.
        resolve: (name) => {
            const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
            const page = pages[`./Pages/${name}.tsx`];

            if (!page) {
                throw new Error(`Inertia page not found while rendering: ${name}`);
            }

            return page as { default: unknown };
        },
        setup: ({ App, props }) => <App {...props} />,
    });
});
