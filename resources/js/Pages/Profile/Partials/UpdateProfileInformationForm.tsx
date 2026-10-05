import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Loader2 } from 'lucide-react';
import { FormEventHandler } from 'react';

/**
 * Tenants have no email verification — there is no email_verified_at column
 * on the table, so the Breeze verification block was removed rather than left
 * to read a field that does not exist.
 *
 * The Save button appears only once something has changed, and the confirmation
 * stays put after it saves — so there is never a button to press with nothing
 * to save, or a doubt about whether it worked.
 */
export default function UpdateProfileInformation() {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful, isDirty } = useForm({
        business_name: user.business_name,
        email: user.email,
        phone: user.phone ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <Field label="Business name" error={errors.business_name} htmlFor="business_name">
                <Input
                    id="business_name"
                    className="h-10"
                    value={data.business_name}
                    onChange={(event) => setData('business_name', event.target.value)}
                    required
                    autoComplete="organization"
                />
            </Field>

            <Field label="Email" error={errors.email} htmlFor="email">
                <Input
                    id="email"
                    type="email"
                    className="h-10"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    required
                    autoComplete="username"
                />
            </Field>

            <Field
                label="Phone"
                hint="Optional — only our team sees it."
                error={errors.phone}
                htmlFor="phone"
            >
                <Input
                    id="phone"
                    type="tel"
                    inputMode="tel"
                    className="h-10"
                    value={data.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                    placeholder="+255 712 345 678"
                    autoComplete="tel"
                />
            </Field>

            <div className="flex items-center gap-3">
                {isDirty && (
                    <Button type="submit" className="w-full sm:w-auto" disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        Save changes
                    </Button>
                )}

                {!isDirty && recentlySuccessful && (
                    <p className="flex items-center gap-1.5 text-sm text-primary">
                        <Check className="size-4" aria-hidden />
                        Saved
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
