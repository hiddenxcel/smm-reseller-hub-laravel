import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Dialog } from 'radix-ui';
import { Loader2, X } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { CustomerRow } from './types';

/**
 * Add a customer, or edit one.
 *
 * One component for both because the fields are the same bar the phone number,
 * which is only editable on create: it is the bot's routing key and the unique
 * key on the table, so changing it would quietly detach the person from their
 * own message history.
 */
export default function CustomerForm({
    open,
    customer,
    onClose,
}: {
    open: boolean;
    /** null means "add"; a row means "edit that row". */
    customer: CustomerRow | null;
    onClose: () => void;
}) {
    const isEdit = customer !== null;

    const form = useForm({
        phone: '',
        name: '',
        email: '',
        country: '',
        lang: 'en',
        notes: '',
        tags: [] as string[],
    });

    const [tagDraft, setTagDraft] = useState('');

    // Reset whenever the dialog is opened, so a half-typed entry from last
    // time never carries into the next customer.
    useEffect(() => {
        if (!open) {
            return;
        }

        form.setDefaults({
            phone: customer?.phone ?? '',
            name: customer?.name ?? '',
            email: customer?.email ?? '',
            country: customer?.country ?? '',
            lang: customer?.lang ?? 'en',
            notes: '',
            tags: customer?.tags ?? [],
        });
        form.reset();
        form.clearErrors();
        setTagDraft('');
        // The form object is new every render; depending on it would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, customer?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (isEdit) {
            form.patch(route('customers.update', customer.id), options);
        } else {
            form.post(route('customers.store'), options);
        }
    };

    const addTag = () => {
        const tag = tagDraft.trim();

        if (tag === '' || form.data.tags.length >= 20) {
            return;
        }

        const exists = form.data.tags.some(
            (existing) => existing.toLowerCase() === tag.toLowerCase(),
        );

        if (!exists) {
            form.setData('tags', [...form.data.tags, tag]);
        }

        setTagDraft('');
    };

    return (
        <Dialog.Root open={open} onOpenChange={(next) => !next && onClose()}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-[60] bg-foreground/25 backdrop-blur-[1px] data-[state=open]:animate-in data-[state=open]:fade-in" />

                <Dialog.Content
                    className="fixed left-1/2 top-1/2 z-[60] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl border border-border bg-background p-5 shadow-2xl data-[state=open]:animate-in data-[state=open]:fade-in data-[state=open]:zoom-in-95"
                    aria-describedby={undefined}
                >
                    <div className="mb-4 flex items-start justify-between gap-3">
                        <Dialog.Title className="font-heading text-lg font-extrabold tracking-tight">
                            {isEdit ? 'Edit customer' : 'Add customer'}
                        </Dialog.Title>
                        <Dialog.Close asChild>
                            <button
                                type="button"
                                className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-accent"
                                aria-label="Close"
                            >
                                <X className="size-4" />
                            </button>
                        </Dialog.Close>
                    </div>

                    <form onSubmit={submit} className="space-y-3">
                        {!isEdit && (
                            <Field label="Phone" error={form.errors.phone} required>
                                <input
                                    value={form.data.phone}
                                    onChange={(event) =>
                                        form.setData('phone', event.target.value)
                                    }
                                    placeholder="255712345678"
                                    inputMode="tel"
                                    className={inputClass}
                                />
                            </Field>
                        )}

                        <Field label="Name" error={form.errors.name}>
                            <input
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={inputClass}
                            />
                        </Field>

                        <Field label="Email" error={form.errors.email}>
                            <input
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                className={inputClass}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Country" error={form.errors.country}>
                                <input
                                    value={form.data.country}
                                    onChange={(event) =>
                                        form.setData(
                                            'country',
                                            event.target.value.toUpperCase().slice(0, 2),
                                        )
                                    }
                                    placeholder="TZ"
                                    maxLength={2}
                                    className={inputClass}
                                />
                            </Field>

                            <Field label="Language" error={form.errors.lang}>
                                <select
                                    value={form.data.lang}
                                    onChange={(event) =>
                                        form.setData('lang', event.target.value)
                                    }
                                    className={inputClass}
                                >
                                    <option value="en">English</option>
                                    <option value="sw">Kiswahili</option>
                                    <option value="fr">Français</option>
                                    <option value="tr">Türkçe</option>
                                    <option value="hi">हिन्दी</option>
                                </select>
                            </Field>
                        </div>

                        {isEdit && (
                            <>
                                <Field label="Tags" error={form.errors.tags}>
                                    <div className="flex gap-2">
                                        <input
                                            value={tagDraft}
                                            onChange={(event) => setTagDraft(event.target.value)}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    event.preventDefault();
                                                    addTag();
                                                }
                                            }}
                                            placeholder="wholesale"
                                            className={inputClass}
                                        />
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={addTag}
                                        >
                                            Add
                                        </Button>
                                    </div>

                                    {form.data.tags.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {form.data.tags.map((tag) => (
                                                <button
                                                    key={tag}
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'tags',
                                                            form.data.tags.filter(
                                                                (item) => item !== tag,
                                                            ),
                                                        )
                                                    }
                                                    className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                                >
                                                    {tag}
                                                    <X className="size-3" aria-hidden />
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </Field>

                                <Field label="Notes" error={form.errors.notes}>
                                    <textarea
                                        value={form.data.notes}
                                        onChange={(event) =>
                                            form.setData('notes', event.target.value)
                                        }
                                        rows={3}
                                        className={`${inputClass} h-auto py-2`}
                                    />
                                </Field>
                            </>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" size="sm" disabled={form.processing}>
                                {form.processing && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                {isEdit ? 'Save' : 'Add customer'}
                            </Button>
                        </div>
                    </form>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}

const inputClass =
    'h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:ring-[3px] focus:ring-ring/30';

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-muted-foreground">
                {label}
                {required && <span className="text-destructive"> *</span>}
            </span>
            {children}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </label>
    );
}
