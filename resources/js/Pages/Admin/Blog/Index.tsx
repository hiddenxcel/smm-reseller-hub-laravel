import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink, Eye, Plus, Send, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { dateTime, Empty } from '../bits';
import { BlogPostRow, BlogPostState } from '../types';

type Props = {
    posts: BlogPostRow[];
    canManage: boolean;
};

const STATE_STYLES: Record<BlogPostState, string> = {
    draft: 'bg-muted text-muted-foreground',
    scheduled: 'bg-accent text-accent-foreground',
    published: 'bg-primary/10 text-primary',
};

export default function BlogIndex({ posts, canManage }: Props) {
    const [editing, setEditing] = useState<BlogPostRow | null>(null);
    const [creating, setCreating] = useState(false);

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-xl font-extrabold">Blog</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Posts on the public site at /blog.
                        </p>
                    </div>

                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-4" />
                            New post
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Blog — Control" />

            <p className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                A post is public once its date is set and in the past. Give it a
                future date to schedule it. The address is fixed when the post is
                created — renaming the title later does not move it, because links
                already shared point at the old one.
            </p>

            {(creating || editing) && canManage && (
                <Editor
                    post={editing}
                    onDone={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}

            {posts.length === 0 ? (
                <Empty>Nothing written yet.</Empty>
            ) : (
                <div className="mt-4 space-y-3">
                    {posts.map((post) => (
                        <Card
                            key={post.id}
                            post={post}
                            canManage={canManage}
                            onEdit={() => {
                                setCreating(false);
                                setEditing(post);
                            }}
                        />
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}

function Card({
    post,
    canManage,
    onEdit,
}: {
    post: BlogPostRow;
    canManage: boolean;
    onEdit: () => void;
}) {
    const act = (action: string, confirmation?: string) => {
        if (confirmation && ! window.confirm(confirmation)) {
            return;
        }

        router.post(route('admin.blog.act', [post.id, action]), {}, { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-heading font-bold">{post.title}</h2>

                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATE_STYLES[post.state]}`}
                        >
                            {post.state}
                        </span>
                    </div>

                    <p className="mt-1 text-sm text-muted-foreground">{post.excerpt}</p>

                    <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span className="font-data">/blog/{post.slug}</span>
                        {post.author && <span>By {post.author}</span>}
                        {post.publishedAt && <span>{dateTime(post.publishedAt)}</span>}
                    </p>
                </div>

                {canManage && (
                    <div className="flex shrink-0 flex-wrap items-center gap-1.5">
                        {post.state === 'published' && (
                            <a
                                href={route('blog.show', post.slug)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                            >
                                <ExternalLink className="size-3.5" />
                                View
                            </a>
                        )}

                        <button
                            type="button"
                            onClick={onEdit}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                        >
                            <Eye className="size-3.5" />
                            Edit
                        </button>

                        {post.state === 'published' ? (
                            <button
                                type="button"
                                onClick={() => act('unpublish')}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                            >
                                <Undo2 className="size-3.5" />
                                Take down
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() =>
                                    act('publish', `Publish "${post.title}" to the public blog?`)
                                }
                                className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                            >
                                <Send className="size-3.5" />
                                Publish
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={() =>
                                act('delete', `Delete "${post.title}"? This cannot be undone.`)
                            }
                            className="inline-flex items-center gap-1.5 rounded-lg border border-destructive/30 px-2.5 py-1.5 text-xs font-medium text-destructive transition-colors hover:bg-destructive/10"
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function Editor({ post, onDone }: { post: BlogPostRow | null; onDone: () => void }) {
    const { data, setData, post: create, patch, processing, errors } = useForm({
        title: post?.title ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        author: post?.author ?? '',
        // datetime-local wants `YYYY-MM-DDTHH:mm`, which is what the ISO string
        // already starts with.
        published_at: post?.publishedAt?.slice(0, 16) ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (post) {
            patch(route('admin.blog.update', post.id), options);
        } else {
            create(route('admin.blog.store'), options);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 space-y-4 rounded-xl border border-primary/30 bg-card p-4"
        >
            <p className="font-heading font-bold">{post ? 'Edit post' : 'New post'}</p>

            <div>
                <label htmlFor="title" className="mb-1.5 block text-sm font-medium">
                    Title
                </label>
                <input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm"
                    required
                />
                {errors.title && (
                    <p className="mt-1 text-sm text-destructive">{errors.title}</p>
                )}
            </div>

            <div>
                <label htmlFor="excerpt" className="mb-1.5 block text-sm font-medium">
                    Excerpt
                </label>
                <textarea
                    id="excerpt"
                    value={data.excerpt}
                    onChange={(e) => setData('excerpt', e.target.value)}
                    rows={2}
                    maxLength={300}
                    className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm"
                    required
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    Shown on the blog index and in link previews. {data.excerpt.length}/300
                </p>
                {errors.excerpt && (
                    <p className="mt-1 text-sm text-destructive">{errors.excerpt}</p>
                )}
            </div>

            <div>
                <label htmlFor="body" className="mb-1.5 block text-sm font-medium">
                    Body
                </label>
                <textarea
                    id="body"
                    value={data.body}
                    onChange={(e) => setData('body', e.target.value)}
                    rows={14}
                    className="font-data w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm"
                    required
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    Blank line between paragraphs. Start a line with{' '}
                    <code className="font-data">##</code> for a heading or{' '}
                    <code className="font-data">-</code> for a bullet. Anything else is
                    printed as written — the page renders text, never markup.
                </p>
                {errors.body && <p className="mt-1 text-sm text-destructive">{errors.body}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor="author" className="mb-1.5 block text-sm font-medium">
                        Author <span className="text-muted-foreground">(optional)</span>
                    </label>
                    <input
                        id="author"
                        value={data.author}
                        onChange={(e) => setData('author', e.target.value)}
                        className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm"
                    />
                </div>

                <div>
                    <label htmlFor="published_at" className="mb-1.5 block text-sm font-medium">
                        Publish at <span className="text-muted-foreground">(optional)</span>
                    </label>
                    <input
                        id="published_at"
                        type="datetime-local"
                        value={data.published_at}
                        onChange={(e) => setData('published_at', e.target.value)}
                        className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Leave empty to keep it a draft.
                    </p>
                </div>
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                    {processing ? 'Saving…' : post ? 'Save changes' : 'Save as draft'}
                </button>

                <button
                    type="button"
                    onClick={onDone}
                    className="rounded-lg border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-accent"
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}
