import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function ConfirmPassword() {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout
            title="Confirm it's you"
            description="This part of your account is protected. Enter your password to carry on."
        >
            <Head title="Confirm Password" />

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>

                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="h-10 pr-10"
                            autoComplete="current-password"
                            autoFocus
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

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    <ShieldCheck className="size-4" />
                    {processing ? 'Confirming…' : 'Confirm'}
                </Button>
            </form>
        </GuestLayout>
    );
}
