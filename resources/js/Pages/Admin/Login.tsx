import InputError from '@/components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * The /hx-control front door.
 *
 * Its own page rather than the tenant GuestLayout: nothing here should invite a
 * reseller who arrived by accident to register, reset a password, or go back to
 * a marketing page. A username box and nothing else.
 */
export default function AdminLogin({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('admin.login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="flex min-h-dvh items-center justify-center bg-slate-900 px-4 py-12">
            <Head title="Control" />

            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center gap-3 text-center">
                    <Shield className="size-10 text-amber-400" />
                    <div>
                        <h1 className="font-heading text-2xl font-extrabold text-white">
                            Control
                        </h1>
                        <p className="mt-1 text-sm text-slate-400">
                            Platform administration
                        </p>
                    </div>
                </div>

                {status && (
                    <div className="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200">
                        {status}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="rounded-xl border border-slate-700 bg-slate-800/60 p-6"
                >
                    <div>
                        <label
                            htmlFor="username"
                            className="block text-sm font-medium text-slate-300"
                        >
                            Username
                        </label>

                        <input
                            id="username"
                            name="username"
                            type="text"
                            value={data.username}
                            autoComplete="username"
                            autoFocus
                            onChange={(e) => setData('username', e.target.value)}
                            className="mt-1.5 block w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-slate-100 placeholder:text-slate-500 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        />

                        <InputError message={errors.username} className="mt-2" />
                    </div>

                    <div className="mt-4">
                        <label
                            htmlFor="password"
                            className="block text-sm font-medium text-slate-300"
                        >
                            Password
                        </label>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            value={data.password}
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1.5 block w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-slate-100 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                        />

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="mt-6 w-full rounded-lg bg-amber-500 px-4 py-2.5 font-semibold text-slate-900 transition-colors hover:bg-amber-400 disabled:opacity-60"
                    >
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>

                <p className="mt-6 text-center text-xs text-slate-500">
                    Every action in this console is recorded.
                </p>
            </div>
        </div>
    );
}
