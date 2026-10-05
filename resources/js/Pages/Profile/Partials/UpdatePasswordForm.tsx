import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Check, Eye, EyeOff, Loader2 } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

export default function UpdatePasswordForm() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [visible, setVisible] = useState(false);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (event) => {
        event.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    const type = visible ? 'text' : 'password';
    const filled = data.current_password !== '' && data.password !== '';

    return (
        <form onSubmit={updatePassword} className="space-y-4">
            <Field label="Current password" error={errors.current_password} htmlFor="current_password">
                <Input
                    id="current_password"
                    ref={currentPasswordInput}
                    type={type}
                    className="h-10"
                    value={data.current_password}
                    onChange={(event) => setData('current_password', event.target.value)}
                    autoComplete="current-password"
                />
            </Field>

            <Field
                label="New password"
                hint="At least 8 characters."
                error={errors.password}
                htmlFor="password"
            >
                <Input
                    id="password"
                    ref={passwordInput}
                    type={type}
                    className="h-10"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    autoComplete="new-password"
                />
            </Field>

            <Field
                label="Confirm new password"
                error={errors.password_confirmation}
                htmlFor="password_confirmation"
            >
                <Input
                    id="password_confirmation"
                    type={type}
                    className="h-10"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                />
            </Field>

            <button
                type="button"
                onClick={() => setVisible(!visible)}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                {visible ? 'Hide passwords' : 'Show passwords'}
            </button>

            <div className="flex items-center gap-3">
                <Button
                    type="submit"
                    className="w-full sm:w-auto"
                    disabled={processing || !filled}
                >
                    {processing && <Loader2 className="size-4 animate-spin" />}
                    Change password
                </Button>

                {recentlySuccessful && (
                    <p className="flex items-center gap-1.5 text-sm text-primary">
                        <Check className="size-4" aria-hidden />
                        Changed
                    </p>
                )}
            </div>
        </form>
    );
}

function Field({
    label,
    hint,
    error,
    htmlFor,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label htmlFor={htmlFor}>{label}</Label>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <div className="mt-1.5">{children}</div>
            {error && <p className="mt-1.5 text-sm text-destructive">{error}</p>}
        </div>
    );
}
