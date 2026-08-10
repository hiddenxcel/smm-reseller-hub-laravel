import Reveal from '@/components/landing/Reveal';
import { Section } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import Seo from '@/components/Seo';
import { ArrowRight, PenLine } from 'lucide-react';

type Post = {
    slug: string;
    title: string;
    excerpt: string;
    author: string | null;
    published_at: string | null;
};

type Props = {
    posts: {
        data: Post[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    demoNumber: string | null;
    assistantEnabled: boolean;
};

function formatted(date: string | null): string {
    if (! date) {
        return '';
    }

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

export default function BlogIndex({ posts, demoNumber, assistantEnabled }: Props) {
    return (
        <PublicLayout
            eyebrow="Blog"
            title="Notes on running an SMM shop"
            description="What we are learning about panels, WhatsApp and getting paid — written for the people doing it."
            demoNumber={demoNumber}
            assistantEnabled={assistantEnabled}
        >
            <Seo
                title="Blog"
                description="Notes on running an SMM reseller shop: keeping your WhatsApp number safe, connecting a panel, taking mobile money and crypto, and automating the questions that eat your evenings."
            />

            <Section className="pt-0">
                {posts.data.length === 0 ? (
                    // An empty blog reads as an abandoned business, so it says
                    // what it is rather than showing nothing.
                    <Reveal className="mx-auto max-w-md text-center">
                        <span className="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-foreground">
                            <PenLine className="size-6" />
                        </span>

                        <h2 className="font-heading text-xl font-bold">
                            The first post is being written
                        </h2>

                        <p className="mt-2 text-sm text-muted-foreground">
                            Nothing here yet. In the meantime, the API docs and the
                            features page have the detail most people come looking for.
                        </p>

                        <div className="mt-6 flex flex-wrap justify-center gap-3">
                            <Button variant="outline" asChild>
                                <Link href={route('features')}>See the features</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={route('api-docs')}>Read the API docs</Link>
                            </Button>
                        </div>
                    </Reveal>
                ) : (
                    <>
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {posts.data.map((post, index) => (
                                <Reveal key={post.slug} delay={index * 70} index={index} card className="h-full">
                                    <Link
                                        href={route('blog.show', post.slug)}
                                        className="group flex h-full flex-col rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-1 hover:border-primary/40"
                                    >
                                        {post.published_at && (
                                            <p className="text-xs text-muted-foreground">
                                                {formatted(post.published_at)}
                                            </p>
                                        )}

                                        <h2 className="font-heading mt-2 text-lg font-bold text-pretty">
                                            {post.title}
                                        </h2>

                                        <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                            {post.excerpt}
                                        </p>

                                        <span className="mt-4 flex items-center gap-1.5 text-sm font-semibold text-primary">
                                            Read
                                            <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-0.5" />
                                        </span>
                                    </Link>
                                </Reveal>
                            ))}
                        </div>

                        {posts.links.length > 3 && (
                            <div className="mt-10 flex flex-wrap justify-center gap-1.5">
                                {posts.links.map((link) => (
                                    <Link
                                        key={link.label}
                                        href={link.url ?? '#'}
                                        disabled={! link.url}
                                        className={[
                                            'rounded-lg px-3 py-1.5 text-sm transition-colors',
                                            link.active
                                                ? 'bg-primary text-primary-foreground'
                                                : link.url
                                                  ? 'text-muted-foreground hover:text-foreground'
                                                  : 'cursor-not-allowed text-muted-foreground/40',
                                        ].join(' ')}
                                    >
                                        {/* Laravel sends &laquo;/&raquo; as HTML entities.
                                            Rendering them as markup would mean trusting
                                            paginator output as HTML for the sake of two
                                            arrows; replacing them is enough. */}
                                        {link.label
                                            .replace('&laquo;', '‹')
                                            .replace('&raquo;', '›')}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </Section>
        </PublicLayout>
    );
}
