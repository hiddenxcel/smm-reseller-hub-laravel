import InputError from '@/components/InputError';
import Reveal from '@/components/landing/Reveal';
import { Section } from '@/components/landing/Section';
import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import Seo from '@/components/Seo';
import { BookOpen, CheckCircle2, Clock, LifeBuoy, Mail, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

type Props = {
    demoNumber: string | null;
    assistantEnabled: boolean;
};

export default function Contact({ demoNumber, assistantEnabled }: Props) {
    const status = (usePage().props as { flash?: { success?: string | null } }).flash
        ?.success;

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        subject: '',
        message: '',
        // Honeypot. Never shown, never filled by a person.
        website: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('contact.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <PublicLayout
            eyebrow="Contact"
            title="Talk to a person"
            description="Questions before signing up, or something not working? Write to us and we will answer by email."
            demoNumber={demoNumber}
            assistantEnabled={assistantEnabled}
        >
            <Seo
                title="Contact"
                description="Questions before signing up, or something not working? Write to the Resellers Hub team and we will answer by email — or reach us on WhatsApp for anything urgent."
            />

            <Section className="pt-0">
                <div className="mx-auto grid max-w-5xl gap-10 lg:grid-cols-[1.2fr_1fr]">
                    {/* ---- the form ---- */}
                    <Reveal>
                        <div className="rounded-2xl border border-border bg-card p-6 sm:p-8">
                            {status && (
                                <div className="mb-6 flex items-start gap-2.5 rounded-lg bg-accent px-3.5 py-3 text-sm text-accent-foreground">
                                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                                    <span>{status}</span>
                                </div>
                            )}

                            <form onSubmit={submit} className="space-y-5">
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Your name</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            className="h-10"
                                            autoComplete="name"
                                            aria-invalid={!! errors.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            className="h-10"
                                            autoComplete="email"
                                            placeholder="you@yourshop.com"
                                            aria-invalid={!! errors.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="subject">Subject</Label>
                                    <Input
                                        id="subject"
                                        value={data.subject}
                                        className="h-10"
                                        placeholder="What is this about?"
                                        aria-invalid={!! errors.subject}
                                        onChange={(e) => setData('subject', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.subject} />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="message">Message</Label>
                                    <Textarea
                                        id="message"
                                        value={data.message}
                                        rows={6}
                                        placeholder="Tell us what you need."
                                        aria-invalid={!! errors.message}
                                        onChange={(e) => setData('message', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.message} />
                                </div>

                                {/* Off-screen rather than display:none — some bots skip
                                    hidden fields, and none of them read this label. */}
                                <div className="absolute -left-[9999px]" aria-hidden>
                                    <label htmlFor="website">Leave this empty</label>
                                    <input
                                        id="website"
                                        type="text"
                                        tabIndex={-1}
                                        autoComplete="off"
                                        value={data.website}
                                        onChange={(e) => setData('website', e.target.value)}
                                    />
                                </div>

                                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                                    <Send className="size-4" />
                                    {processing ? 'Sending…' : 'Send message'}
                                </Button>
                            </form>
                        </div>
                    </Reveal>

                    {/* ---- other ways in ---- */}
                    <Reveal delay={120}>
                        <div className="space-y-4">
                            {demoNumber && (
                                <a
                                    href={`https://wa.me/${demoNumber.replace(/\D/g, '')}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-start gap-3 rounded-2xl border border-primary/25 bg-primary/5 p-5 transition-colors hover:border-primary/50"
                                >
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                                        <LifeBuoy className="size-5 text-primary" />
                                    </span>
                                    <span>
                                        <span className="block font-semibold">WhatsApp</span>
                                        <span className="block text-sm text-muted-foreground">
                                            The fastest way to reach us
                                        </span>
                                    </span>
                                </a>
                            )}

                            <div className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent">
                                    <Mail className="size-5 text-accent-foreground" />
                                </span>
                                <span>
                                    <span className="block font-semibold">Email</span>
                                    <span className="block text-sm text-muted-foreground">
                                        Use the form — it reaches the same inbox and we can
                                        reply to it directly.
                                    </span>
                                </span>
                            </div>

                            <div className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent">
                                    <Clock className="size-5 text-accent-foreground" />
                                </span>
                                <span>
                                    <span className="block font-semibold">When we reply</span>
                                    <span className="block text-sm text-muted-foreground">
                                        Usually within a day. WhatsApp is faster if it is
                                        urgent.
                                    </span>
                                </span>
                            </div>

                            <Link
                                href={route('api-docs')}
                                className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5 transition-colors hover:border-primary/40"
                            >
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent">
                                    <BookOpen className="size-5 text-accent-foreground" />
                                </span>
                                <span>
                                    <span className="block font-semibold">Building something?</span>
                                    <span className="block text-sm text-muted-foreground">
                                        The API docs may answer it faster than we can.
                                    </span>
                                </span>
                            </Link>
                        </div>
                    </Reveal>
                </div>
            </Section>
        </PublicLayout>
    );
}
