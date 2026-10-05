import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    ContactSection,
    IdentitySection,
    PersonalSection,
    PreferencesSection,
} from '@/components/pms/registration-card/guest-details-sections';
import { SignSection } from '@/components/pms/registration-card/sign-section';
import { StaySummary } from '@/components/pms/registration-card/stay-summary';
import { TermsSection } from '@/components/pms/registration-card/terms-section';
import { useCardForm } from '@/components/pms/registration-card/use-card-form';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { guest, sign } from '@/routes/registration';

type Card = App.Data.Registration.RegistrationCardPageData;

type Options = {
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
};

/**
 * The guest screen's card, in section cards: the stay, the guest's details by the Layout tab,
 * the terms with their tick boxes in place, and the signature.
 * Typed values stay when the language changes, but the ticks reset because the wording changes.
 */
export function RegistrationCardForm({
    card,
    genders,
    identityTypes,
    countries,
}: { card: Card } & Options) {
    const word = (text: string) => card.labels[text] ?? text;
    const visibility = Object.fromEntries(
        card.fields.map((field) => [field.field, field.visibility]),
    );
    const has = (field: string) => field in visibility;
    const mark = (field: string | null, label: string) =>
        field !== null && visibility[field] === 'required'
            ? `${word(label)} *`
            : word(label);

    const form = useCardForm(card);

    // A new language has new wording, so earlier ticks no longer apply.
    useEffect(() => {
        form.setData((data) => ({ ...data, locale: card.locale, ticks: [] }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [card.locale]);

    const switchLanguage = (locale: string) =>
        router.get(
            guest.url({ unitGuest: card.unitGuestId }),
            { locale },
            {
                only: ['card'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );

    const boxesLeft = card.checklist.filter(
        (item) => !form.data.ticks.includes(item.position),
    ).length;
    const missing = [
        boxesLeft > 0
            ? word(':count to confirm').replace(':count', String(boxesLeft))
            : null,
        form.data.signature === '' ? word('Signature needed') : null,
        form.data.signer_name.trim() === '' ? word('Name of signer') : null,
    ].filter((message) => message !== null);
    const ready = missing.length === 0;

    const section = { card, form, word, mark, has };
    const stayFields = card.fields
        .filter((field) => field.value !== null && field.group !== 'guest')
        .map((field) => ({ ...field, label: word(field.label) }));

    return (
        <form
            className="flex flex-col gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(sign.url({ unitGuest: card.unitGuestId }), {
                    preserveScroll: true,
                });
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="font-heading text-xl font-semibold">
                        {word('Welcome, :name').replace(
                            ':name',
                            card.guestName,
                        )}
                    </h1>
                    {card.walkPosition !== null && card.walkTotal > 1 && (
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {word('Guest :position of :total')
                                .replace(':position', String(card.walkPosition))
                                .replace(':total', String(card.walkTotal))}
                        </p>
                    )}
                </div>
                <EnumSelect
                    aria-label={word('Language')}
                    className="w-56"
                    options={card.localeOptions}
                    value={card.locale}
                    onValueChange={switchLanguage}
                />
            </div>

            <StaySummary
                fields={stayFields}
                title={word('Your stay')}
                locale={card.locale}
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <div className="flex flex-col gap-6">
                    <PersonalSection
                        {...section}
                        genders={genders}
                        countries={countries}
                    />
                    <ContactSection {...section} countries={countries} />
                    <IdentitySection
                        {...section}
                        identityTypes={identityTypes}
                        countries={countries}
                    />
                    <PreferencesSection {...section} />
                </div>
                <div className="flex flex-col gap-6">
                    <TermsSection {...section} />
                    <SignSection {...section} />
                </div>
            </div>

            <div className="sticky bottom-0 flex items-center justify-between gap-3 border-t bg-background py-3">
                <p className="text-sm text-muted-foreground">
                    {missing.join(' · ')}
                </p>
                <Button
                    type="submit"
                    disabled={!ready || form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner />}
                    {word('Sign card')}
                </Button>
            </div>
        </form>
    );
}
