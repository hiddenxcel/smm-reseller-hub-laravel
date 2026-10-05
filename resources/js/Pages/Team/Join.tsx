import InputError from '@/components/InputError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Loader2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Props =
    | { valid: false }
    | { valid: true; email: string; name: string | null; business: string; role: string };

/**
 * Where an invited person accepts and chooses a password.
 *
 * An expired link and a used one look the same on purpose — telling them apart
 * would only help someone guessing links. Either way the way out is the same:
 * ask whoever invited you for a new one.
 */
export default function Join(props: Props) {
    if (!props.valid) {
        return (
            <GuestLayout title="This link has expired">
                <Head title="Invite expired" />

                <div className="flex items-start gap-3 rounded-xl border border-border bg-card p-4 text-sm">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p className="text-muted-foreground">
                        It may be older than a week, or already used. Ask whoever invited you to
                        send a new link.
                    </p>
                </div>

                <p className="mt-6 text-sm text-muted-foreground">
                    Already set up?{' '}
                    <Link href={route('login')} className="font-medium text-primary underline">
                        Sign in
                    </Link>
                </p>
            </GuestLayout>
        );
    }

    return <Accept {...props} />;
}

function Accept({
    email,
    name,
    business,
    role,
}: {
    email: string;
    name: string | null;
    business: string;
    role: string;
}) {
    const token = window.location.pathname.split('/').pop() ?? '';
    const [visible, setVisible] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        name: name ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('team.join.store', token));
    };

    return (
        <GuestLayout
            title={`Join ${business}`}
            description={
                <>
                    You have been invited as <strong>{role}</strong>. Choose a password to sign in
                    with.
                </>
            }
        >
            <Head title={`Join ${business}`} />

            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" value={email} className="h-10" disabled readOnly />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="name">Your name</Label>
                    <Input
                        id="name"
                        className="h-10"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        autoComplete="name"
                        autoFocus
                        required
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <p className="text-xs text-muted-foreground">At least 8 characters.</p>
                    <Input
                        id="password"
                        type={visible ? 'text' : 'password'}
                        className="h-10"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        autoComplete="new-password"
                        required
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type={visible ? 'text' : 'password'}
                        className="h-10"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        autoComplete="new-password"
                        required
                    />
                </div>

                <button
                    type="button"
                    onClick={() => setVisible(!visible)}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    {visible ? 'Hide password' : 'Show password'}
                </button>

                <Button type="submit" className="h-10 w-full" disabled={processing}>
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    Join and sign in
                </Button>
            </form>
        </GuestLayout>
    );
}
