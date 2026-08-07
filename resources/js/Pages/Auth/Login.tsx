import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Eye, EyeOff, LogIn } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            title="Welcome back"
            description="Sign in to pick up where your shop left off."
        >
            <Head title="Log in" />

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

                <div className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <Label htmlFor="password">Password</Label>

                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="rounded-sm text-xs font-medium text-muted-foreground transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>

                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="h-10 pr-10"
                            autoComplete="current-password"
                            placeholder="••••••••"
                            aria-invalid={!!errors.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />

                        <button
                            type="button"
                            onClick={() => setShowPassword((value) => !value)}
                            className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                        >
                            {showPassword ? (
                                <EyeOff className="size-4" />
                            ) : (
                                <Eye className="size-4" />
                            )}
                        </button>
                    </div>

                    <InputError message={errors.password} />
                </div>

                <label className="flex w-fit items-center gap-2.5 text-sm text-muted-foreground select-none">
                    <input
                        type="checkbox"
                        name="remember"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="size-4 rounded border-input text-primary accent-primary focus-visible:ring-3 focus-visible:ring-ring/50"
                    />
                    Keep me signed in
                </label>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    <LogIn className="size-4" />
                    {processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            <p className="mt-8 text-center text-sm text-muted-foreground">
                New here?{' '}
                <Link
                    href={route('register')}
                    className="rounded-sm font-semibold text-primary transition-opacity hover:opacity-80 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                    Create a free account
                </Link>
            </p>
        </GuestLayout>
    );
}
