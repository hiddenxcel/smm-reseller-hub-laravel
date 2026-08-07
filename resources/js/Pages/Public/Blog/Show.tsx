import Reveal from '@/components/landing/Reveal';
import { Section } from '@/components/landing/Section';
import Seo from '@/components/Seo';
import PublicLayout from '@/Layouts/PublicLayout';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';

type Post = {
    slug: string;
    title: string;
    excerpt: string;
    body: string;
    author: string | null;
    published_at: string | null;
};

type Related = {
    slug: string;
    title: string;
    excerpt: string;
    published_at: string | null;
};

type Props = {
    post: Post;
    more: Related[];
    demoNumber: string | null;
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

/**
 * Renders the body as text, not as markup.
 *
 * Post bodies are written by us, but rendering them as HTML would mean any
 * future path into that column — an import, an admin bug — becomes stored
 * XSS on a public page. Splitting on blank lines gives paragraphs and
 * headings without ever handing a string to the HTML parser.
 */
function Body({ text }: { text: string }) {
    const blocks = text.split(/\n{2,}/).filter((block) => block.trim() !== '');

    return (
        <div className="space-y-5">
            {blocks.map((block, index) => {
                const trimmed = block.trim();

                if (trimmed.startsWith('## ')) {
                    return (
                        <h2
                            key={index}
                            className="font-heading pt-4 text-xl font-bold text-balance"
                        >
                            {trimmed.slice(3)}
                        </h2>
                    );
                }

                if (trimmed.startsWith('### ')) {
                    return (
                        <h3 key={index} className="font-heading pt-2 font-bold text-balance">
                            {trimmed.slice(4)}
                        </h3>
                    );
                }

                // A run of "- " lines is a list.
                if (trimmed.split('\n').every((line) => line.trim().startsWith('- '))) {
                    return (
                        <ul key={index} className="list-disc space-y-1.5 pl-5">
                            {trimmed.split('\n').map((line, lineIndex) => (
                                <li key={lineIndex} className="text-pretty">
                                    {line.trim().slice(2)}
                                </li>
                            ))}
                        </ul>
                    );
                }

                return (
                    <p key={index} className="leading-relaxed text-pretty">
                        {trimmed}
                    </p>
                );
            })}
        </div>
    );
}

export default function BlogShow({ post, more, demoNumber }: Props) {
    return (
        <PublicLayout
            eyebrow={formatted(post.published_at)}
            title={post.title}
            description={post.excerpt}
            demoNumber={demoNumber}
        >
            {/* Through Seo rather than a bare Head: its tags are keyed, so a
                post's description replaces the site default instead of
                leaving both in the document for a crawler to choose between. */}
            <Seo title={post.title} description={post.excerpt} />

            <Section className="pt-0">
                <Reveal className="mx-auto max-w-2xl">
                    {post.author && (
                        <p className="mb-8 text-center text-sm text-muted-foreground">
                            By {post.author}
                        </p>
                    )}

                    <article className="text-muted-foreground">
                        <Body text={post.body} />
                    </article>

                    <div className="mt-12 border-t border-border pt-6">
                        <Link
                            href={route('blog')}
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" />
                            All posts
                        </Link>
                    </div>
                </Reveal>
            </Section>

            {more.length > 0 && (
                <Section muted>
                    <h2 className="font-heading mb-8 text-center text-xl font-bold">
                        More from the blog
                    </h2>

                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {more.map((other, index) => (
                            <Reveal key={other.slug} delay={index * 70} index={index} card className="h-full">
                                <Link
                                    href={route('blog.show', other.slug)}
                                    className="group flex h-full flex-col rounded-2xl border border-border bg-card p-6 transition-all duration-300 hover:-translate-y-1 hover:border-primary/40"
                                >
                                    {other.published_at && (
                                        <p className="text-xs text-muted-foreground">
                                            {formatted(other.published_at)}
                                        </p>
                                    )}

                                    <h3 className="font-heading mt-2 font-bold text-pretty">
                                        {other.title}
                                    </h3>

                                    <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                        {other.excerpt}
                                    </p>

                                    <span className="mt-4 flex items-center gap-1.5 text-sm font-semibold text-primary">
                                        Read
                                        <ArrowRight className="size-4 transition-transform duration-300 group-hover:translate-x-0.5" />
                                    </span>
                                </Link>
                            </Reveal>
                        ))}
                    </div>
                </Section>
            )}
        </PublicLayout>
    );
}
