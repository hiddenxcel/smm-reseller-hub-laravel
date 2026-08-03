import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Card, Field, inputClass } from './bits';

/**
 * Connect a panel by probing it.
 *
 * The reseller gives a URL and a key; detection works out the rest — which API
 * shape it speaks, whether the key goes in a header or a parameter — because
 * asking a reseller for their panel's auth method is asking them something
 * they have no way to know.
 *
 * Failure comes back on the URL field, since a wrong address is the usual
 * cause and that is where the eye goes.
 */
export function AddProviderForm() {
    const form = useForm({ name: '', api_url: '', api_key: '' });

    return (
        <Card
            title="Add a provider"
            description="Paste your panel's address and API key. We work out the rest."
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('order-bot.providers.store'), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
                className="space-y-4"
            >
                <Field label="Name" hint="Yours to recognise it by" error={form.errors.name}>
                    <input
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        placeholder="My Panel"
                        className={inputClass}
                    />
                </Field>

                <Field
                    label="Panel address"
                    hint="The site you log in to, not the API path"
                    error={form.errors.api_url}
                >
                    <input
                        value={form.data.api_url}
                        onChange={(event) => form.setData('api_url', event.target.value)}
                        placeholder="https://yourpanel.com"
                        className={inputClass}
                    />
                </Field>

                <Field
                    label="API key"
                    hint="Found under API or Account on your panel"
                    error={form.errors.api_key}
                >
                    <input
                        type="password"
                        value={form.data.api_key}
                        onChange={(event) => form.setData('api_key', event.target.value)}
                        autoComplete="off"
                        className={inputClass}
                    />
                </Field>

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {form.processing ? (
                        'Checking the panel…'
                    ) : (
                        <>
                            <Search className="size-4" />
                            Detect and connect
                        </>
                    )}
                </Button>

                <p className="text-xs text-muted-foreground">
                    We call your panel once to confirm the key works and read your balance.
                    The key is stored encrypted and never shown again.
                </p>
            </form>
        </Card>
    );
}
