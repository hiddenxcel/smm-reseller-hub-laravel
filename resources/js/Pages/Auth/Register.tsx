import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Rocket } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export default function Register() {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        business_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout
            title="Create your account"
            description="Free to start, no card required. You'll be selling in about five minutes."
        >
            <Head title="Register" />

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="business_name">Business name</Label>

                    <Input
                        id="business_name"
                        name="business_name"
                        value={data.business_name}
                        className="h-10"
                        autoComplete="organization"
                        autoFocus
                        placeholder="Your shop's name"
                        aria-invalid={!!errors.business_name}
                        onChange={(e) => setData('business_name', e.target.value)}
                        required
                    />

                    <p className="text-xs text-muted-foreground">
                        This is the name your customers see on WhatsApp.
                    </p>

                    <InputError message={errors.business_name} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>

                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="h-10"
                        autoComplete="username"
                        placeholder="you@yourshop.com"
                        aria-invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />

                    <InputError message={errors.email} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="phone">
                        Phone
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>

                    <Input
                        id="phone"
                        type="tel"
                        name="phone"
                        value={data.phone}
                        className="h-10"
                        autoComplete="tel"
                        placeholder="+255 700 000 000"
                        aria-invalid={!!errors.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                    />

                    <InputError message={errors.phone} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>

                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="h-10 pr-10"
                            autoComplete="new-password"
                            placeholder="At least 8 characters"
                            aria-invalid={!!errors.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
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
                        required
                    />

                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    <Rocket className="size-4" />
                    {processing ? 'Creating account…' : 'Create free account'}
                </Button>

                <p className="text-center text-xs text-muted-foreground">
                    By signing up you agree to our terms and privacy policy.
                </p>
            </form>

            <p className="mt-8 text-center text-sm text-muted-foreground">
                Already have an account?{' '}
                <Link
                    href={route('login')}
                    className="rounded-sm font-semibold text-primary transition-opacity hover:opacity-80 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                    Sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
