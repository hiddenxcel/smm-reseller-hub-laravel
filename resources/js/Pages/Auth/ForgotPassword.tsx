import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout
            title="Reset your password"
            description="Tell us the email you signed up with and we'll send you a link to choose a new password."
        >
            <Head title="Forgot Password" />

            {status && (
                <div className="mb-6 flex items-start gap-2.5 rounded-lg bg-accent px-3.5 py-3 text-sm text-accent-foreground">
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    <span>{status}</span>
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>

                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="h-10"
                        autoComplete="username"
                        autoFocus
                        placeholder="you@yourshop.com"
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} />
                </div>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    <Mail className="size-4" />
                    {processing ? 'Sending…' : 'Email me a reset link'}
                </Button>
            </form>

            <p className="mt-8 text-center text-sm">
                <Link
                    href={route('login')}
                    className="inline-flex items-center gap-1.5 rounded-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                    <ArrowLeft className="size-4" />
                    Back to sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
