import { Head, usePage } from '@inertiajs/react';

/**
 * Per-page search and social tags.
 *
 * app.blade.php sets defaults for every page. These override them, and
 * Inertia emits them after the Blade ones so they win.
 *
 * Every tag is keyed, and the same keys appear as `inertia="..."` on the
 * Blade defaults. That pairing is what makes this a replacement rather than
 * an addition — without it the document carries two descriptions and a
 * crawler picks whichever it likes.
 */
export default function Seo({
    title,
    description,
    /** Absolute URL. Defaults to the site logo. */
    image,
}: {
    title: string;
    description: string;
    image?: string;
}) {
    const fullTitle = `${title} — Resellers Hub`;

    // Spelled out rather than left to Blade. Blade's copy is only written when
    // the page is not server-rendered — under SSR these keyed tags are the
    // whole set — so a page that names no image needs one supplied here, or a
    // shared link unfurls as a bare title with an empty square where the card
    // image goes.
    //
    // The origin comes from the shared Ziggy prop rather than from window,
    // which does not exist in the Node process that renders this page.
    const { ziggy } = usePage().props;
    const card = image ?? `${ziggy?.url ?? ''}/logo.png`;

    return (
        <Head title={title}>
            <meta name="description" content={description} head-key="description" />

            <meta property="og:title" content={fullTitle} head-key="og:title" />
            <meta
                property="og:description"
                content={description}
                head-key="og:description"
            />

            <meta name="twitter:title" content={fullTitle} head-key="twitter:title" />
            <meta
                name="twitter:description"
                content={description}
                head-key="twitter:description"
            />

            <meta property="og:image" content={card} head-key="og:image" />
            <meta name="twitter:image" content={card} head-key="twitter:image" />
        </Head>
    );
}
