import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Card, Field, Toggle, inputClass } from './bits';
import { PhoneList } from './PhoneList';
import { Language, Settings } from './types';

/**
 * The shop's own settings — currency, language, who counts as staff.
 *
 * Test numbers matter more than they look: while the subscription is in
 * sandbox they are the only numbers the bot will answer, which is how a
 * reseller tries the bot before paying for it.
 */
export function SettingsTab({
    settings,
    languages,
}: {
    settings: Settings;
    languages: Language[];
}) {
    const form = useForm(settings);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.settings'), { preserveScroll: true });
            }}
            className="space-y-6"
        >
            <Card title="Shop">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Currency" hint="Three letters, e.g. USD" error={form.errors.currency}>
                        <input
                            value={form.data.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                            maxLength={3}
                            className={`${inputClass} uppercase`}
                        />
                    </Field>

                    <Field
                        label="Default language"
                        hint="Used until a customer picks their own"
                        error={form.errors.lang}
                    >
                        <select
                            value={form.data.lang}
                            onChange={(event) => form.setData('lang', event.target.value)}
                            className={inputClass}
                        >
                            {languages.map((language) => (
                                <option key={language.code} value={language.code}>
                                    {language.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Minimum top-up" error={form.errors.minTopup}>
                        <input
                            type="number"
                            min={0}
                            step="0.01"
                            value={form.data.minTopup}
                            onChange={(event) => form.setData('minTopup', event.target.value)}
                            className={inputClass}
                        />
                    </Field>

                    <Field
                        label="Referral bonus"
                        hint="Percent of a referred customer's spend"
                        error={form.errors.referralPercent}
                    >
                        <input
                            type="number"
                            min={0}
                            max={100}
                            step="0.1"
                            value={form.data.referralPercent}
                            onChange={(event) =>
                                form.setData('referralPercent', event.target.value)
                            }
                            className={inputClass}
                        />
                    </Field>
                </div>
            </Card>

            <Card title="Replies">
                <div className="divide-y divide-border">
                    <Toggle
                        label="Show provider name"
                        description="Reveals which panel fulfils an order. Most resellers leave this off."
                        checked={form.data.showProviderName}
                        onChange={(value) => form.setData('showProviderName', value)}
                    />
                    <Toggle
                        label="Detailed status"
                        description="Include start count and remaining amount when reporting progress."
                        checked={form.data.detailedStatus}
                        onChange={(value) => form.setData('detailedStatus', value)}
                    />
                </div>
            </Card>

            <Card
                title="Staff numbers"
                description="These bypass anti-spam and receive bot notifications."
            >
                <PhoneList
                    numbers={form.data.staff}
                    onChange={(numbers) => form.setData('staff', numbers)}
                    empty="No staff numbers yet."
                />
            </Card>

            <Card
                title="Test numbers"
                description="While the subscription is in sandbox, only these numbers get replies."
            >
                <PhoneList
                    numbers={form.data.testNumbers}
                    onChange={(numbers) => form.setData('testNumbers', numbers)}
                    empty="No test numbers yet."
                />
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}

