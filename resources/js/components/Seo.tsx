import { Head } from '@inertiajs/react';

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
    /** Absolute URL. Falls back to the site logo set in Blade. */
    image,
}: {
    title: string;
    description: string;
    image?: string;
}) {
    const fullTitle = `${title} — Resellers Hub`;

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

            {image && (
                <>
                    <meta property="og:image" content={image} head-key="og:image" />
                    <meta name="twitter:image" content={image} head-key="twitter:image" />
                </>
            )}
        </Head>
    );
}
