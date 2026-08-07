import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            title="Choose a new password"
            description="Pick something you haven't used before — you'll be signed in straight after."
        >
            <Head title="Reset Password" />

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
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">New password</Label>

                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="h-10 pr-10"
                            autoComplete="new-password"
                            autoFocus
                            placeholder="At least 8 characters"
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

                <div className="space-y-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>

                    <Input
                        id="password_confirmation"
                        type={showPassword ? 'text' : 'password'}
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="h-10"
                        autoComplete="new-password"
                        placeholder="Type it once more"
                        aria-invalid={!!errors.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                    />

                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    <KeyRound className="size-4" />
                    {processing ? 'Saving…' : 'Reset password'}
                </Button>
            </form>
        </GuestLayout>
    );
}
