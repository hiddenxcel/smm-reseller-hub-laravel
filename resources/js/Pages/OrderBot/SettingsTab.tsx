import { useForm } from '@inertiajs/react';
import { Card, Field, SaveBar, Toggle, inputClass } from './bits';
import { PhoneList } from './PhoneList';
import { StaffAlertsCard } from './StaffAlertsCard';
import { CurrencyOption, Language, Settings } from './types';

/**
 * The shop's own settings — currency, language, replies.
 *
 * Staff numbers are edited under Setup, next to the support choice they
 * belong to, and are sent back here unchanged.
 *
 * Test numbers matter more than they look: while the subscription is in
 * sandbox they are the only numbers the bot will answer, which is how a
 * reseller tries the bot before paying for it.
 */
export function SettingsTab({
    settings,
    languages,
    currencies,
}: {
    settings: Settings;
    languages: Language[];
    currencies: CurrencyOption[];
}) {
    const { staffAlerts, ...editable } = settings;
    const form = useForm(editable);

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('order-bot.settings'), { preserveScroll: true });
            }}
            className="space-y-4 sm:space-y-6"
        >
            <Card title="Shop">
                <div className="grid grid-cols-2 gap-4">
                    <Field
                        label="Currency"
                        hint={currencyHint(form.data.currency, currencies)}
                        error={form.errors.currency}
                    >
                        {/* Picked, not typed: only these have an exchange rate, so
                            only these can be charged through a payment gateway. A
                            currency saved back when this was free text is kept in
                            the list, marked, so opening the page never silently
                            swaps it for another. */}
                        <select
                            value={form.data.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                            className={inputClass}
                        >
                            {currencies.map((currency) => (
                                <option key={currency.code} value={currency.code}>
                                    {currency.code} — {currency.name}
                                </option>
                            ))}
                            {!currencies.some((currency) => currency.code === form.data.currency) && (
                                <option value={form.data.currency}>
                                    {form.data.currency} — not supported
                                </option>
                            )}
                        </select>
                    </Field>

                    <Field
                        label="Language"
                        hint="Until a customer picks theirs"
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
                        hint="% of their spend"
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
                <div className="-my-3 divide-y divide-border">
                    <Toggle
                        label="Show provider name"
                        description="Reveals which panel fulfils an order. Usually off."
                        checked={form.data.showProviderName}
                        onChange={(value) => form.setData('showProviderName', value)}
                    />
                    <Toggle
                        label="Detailed status"
                        description="Add start count and remaining amount to progress replies."
                        checked={form.data.detailedStatus}
                        onChange={(value) => form.setData('detailedStatus', value)}
                    />
                </div>
            </Card>

            <Card
                title="Test numbers"
                description="In test mode, only these numbers get replies."
            >
                <PhoneList
                    numbers={form.data.testNumbers}
                    onChange={(numbers) => form.setData('testNumbers', numbers)}
                    empty="No test numbers yet."
                />
            </Card>

            <StaffAlertsCard bot="order" data={staffAlerts} />

            <SaveBar processing={form.processing} dirty={form.isDirty} />
        </form>
    );
}


/** What the chosen currency means for payments, in one line under the field. */
function currencyHint(code: string, currencies: CurrencyOption[]): string {
    const chosen = currencies.find((currency) => currency.code === code);

    if (!chosen) {
        return 'No exchange rate — pick another to take gateway payments.';
    }

    if (chosen.code === 'USD') {
        return 'Other currencies are converted from dollars.';
    }

    return `1 USD = ${chosen.perUsd.toLocaleString('en-US')} ${chosen.code}`;
}
