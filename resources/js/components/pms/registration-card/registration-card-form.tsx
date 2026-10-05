import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { IdentityDocumentFields } from '@/components/pms/registration-card/identity-document-fields';
import { SignatureField } from '@/components/pms/registration-card/signature-field';
import { TermsPreview } from '@/components/pms/registration-card/terms-preview';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { guest, sign } from '@/routes/registration';

type Card = App.Data.Registration.RegistrationCardPageData;

type Options = {
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
};

/**
 * The guest screen's card: the guest's details by the Layout tab, the terms with their tick boxes in place, and the signature.
 * Typed values stay when the language changes, but the ticks reset because the wording changes.
 * An ID document picked by staff shows read-only.
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
    const required = (field: string | null) =>
        field !== null && visibility[field] === 'required';
    const mark = (field: string | null, label: string) =>
        required(field) ? `${word(label)} *` : word(label);

    const form = useForm({
        full_name: card.values.full_name as string,
        nationality_code: (card.values.nationality_code as string) ?? '',
        date_of_birth: (card.values.date_of_birth as string) ?? '',
        gender: (card.values.gender as string) ?? '',
        phone: (card.values.phone as string) ?? '',
        email: (card.values.email as string) ?? '',
        address_line1: (card.values.address_line1 as string) ?? '',
        address_line2: (card.values.address_line2 as string) ?? '',
        city: (card.values.city as string) ?? '',
        state: (card.values.state as string) ?? '',
        postal_code: (card.values.postal_code as string) ?? '',
        country_code: (card.values.country_code as string) ?? '',
        vehicle_plate: (card.values.vehicle_plate as string) ?? '',
        marketing_consent: card.values.marketing_consent as boolean,
        identity_document_id:
            card.cardDocumentId !== null
                ? String(card.cardDocumentId)
                : card.documents[0]
                  ? String(card.documents[0].id)
                  : '',
        identity: {
            type: 'mykad',
            number: '',
            issuing_country_code: '',
            expires_on: '',
        },
        locale: card.locale,
        ticks: [] as number[],
        signer_name: card.guestName,
        signature: '',
    });
    const errors = form.errors as Record<string, string>;

    // A new language has new wording, so earlier ticks no longer apply.
    useEffect(() => {
        form.setData((data) => ({ ...data, locale: card.locale, ticks: [] }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [card.locale]);

    const text = (
        name: keyof typeof form.data,
        label: string,
        field: string | null,
        type = 'text',
        placeholder?: string,
    ) => (
        <Field data-invalid={!!errors[name]}>
            <FieldLabel htmlFor={`card-${name}`}>
                {mark(field, label)}
            </FieldLabel>
            <Input
                id={`card-${name}`}
                type={type}
                placeholder={placeholder}
                value={form.data[name] as string}
                onChange={(event) =>
                    form.setData(name, event.target.value as never)
                }
                aria-invalid={!!errors[name]}
            />
            <FieldError>{errors[name]}</FieldError>
        </Field>
    );

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

    const allTicked = card.checklist.every((item) =>
        form.data.ticks.includes(item.position),
    );
    const ready =
        allTicked &&
        form.data.signature !== '' &&
        form.data.signer_name.trim() !== '';
    const toggleAll = () =>
        form.setData(
            'ticks',
            allTicked ? [] : card.checklist.map((item) => item.position),
        );
    const displayFields = card.fields.filter((field) => field.value !== null);
    const pickedDocument = card.documents.find(
        (document) => document.id === card.cardDocumentId,
    );
    const welcome = word('Welcome, :name').replace(':name', card.guestName);

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
                        {welcome}
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

            <dl className="flex flex-wrap gap-x-6 gap-y-2 rounded-lg border bg-muted/30 p-3 text-sm">
                {displayFields.map((field) => (
                    <div key={field.field} className="flex gap-1.5">
                        <dt className="text-muted-foreground">
                            {word(field.label)}
                        </dt>
                        <dd className="font-medium tabular-nums">
                            {field.value}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="grid items-start gap-6 lg:grid-cols-2">
                <section className="flex flex-col gap-3">
                    <h4 className="text-sm font-medium">
                        {word('Guest details')}
                    </h4>
                    <FieldGroup className="grid gap-4 sm:grid-cols-2">
                        {text(
                            'full_name',
                            'Full name',
                            'full_name',
                            'text',
                            'Aisyah Rahman',
                        )}
                        {has('nationality') && (
                            <Field data-invalid={!!errors.nationality_code}>
                                <FieldLabel htmlFor="card-nationality_code">
                                    {mark('nationality', 'Nationality')}
                                </FieldLabel>
                                <CountrySelect
                                    id="card-nationality_code"
                                    value={form.data.nationality_code}
                                    onValueChange={(value) =>
                                        form.setData('nationality_code', value)
                                    }
                                    options={countries}
                                    invalid={!!errors.nationality_code}
                                />
                                <FieldError>
                                    {errors.nationality_code}
                                </FieldError>
                            </Field>
                        )}
                        {has('date_of_birth') &&
                            text(
                                'date_of_birth',
                                'Date of birth',
                                'date_of_birth',
                                'date',
                            )}
                        {has('gender') && (
                            <Field data-invalid={!!errors.gender}>
                                <FieldLabel htmlFor="card-gender">
                                    {mark('gender', 'Gender')}
                                </FieldLabel>
                                <EnumSelect
                                    id="card-gender"
                                    options={genders.map((option) => ({
                                        ...option,
                                        label: word(option.label),
                                    }))}
                                    allLabel={word('Select')}
                                    value={form.data.gender}
                                    onValueChange={(value) =>
                                        form.setData('gender', value)
                                    }
                                    aria-invalid={!!errors.gender}
                                />
                                <FieldError>{errors.gender}</FieldError>
                            </Field>
                        )}
                        {has('phone') &&
                            text(
                                'phone',
                                'Phone',
                                'phone',
                                'tel',
                                '+60 12 345 6789',
                            )}
                        {has('email') &&
                            text(
                                'email',
                                'Email',
                                'email',
                                'email',
                                'name@example.com',
                            )}
                        {has('vehicle_plate') &&
                            text(
                                'vehicle_plate',
                                'Vehicle plate',
                                'vehicle_plate',
                                'text',
                                'WXY 1234',
                            )}
                    </FieldGroup>
                    {has('address') && (
                        <FieldGroup className="grid gap-4 sm:grid-cols-2">
                            {text(
                                'address_line1',
                                'Address line 1',
                                'address',
                                'text',
                                word('Street and number'),
                            )}
                            {text(
                                'address_line2',
                                'Address line 2',
                                null,
                                'text',
                                word('Unit, floor or building'),
                            )}
                            {text('city', 'City', null, 'text', 'Kuala Lumpur')}
                            {text('state', 'State', null, 'text', 'Selangor')}
                            {text(
                                'postal_code',
                                'Postal code',
                                null,
                                'text',
                                '50450',
                            )}
                            <Field data-invalid={!!errors.country_code}>
                                <FieldLabel htmlFor="card-country_code">
                                    {word('Country')}
                                </FieldLabel>
                                <CountrySelect
                                    id="card-country_code"
                                    value={form.data.country_code}
                                    onValueChange={(value) =>
                                        form.setData('country_code', value)
                                    }
                                    options={countries}
                                    invalid={!!errors.country_code}
                                />
                                <FieldError>{errors.country_code}</FieldError>
                            </Field>
                        </FieldGroup>
                    )}
                    {has('identity_document') && (
                        <FieldGroup className="grid gap-4 sm:grid-cols-2">
                            {pickedDocument ? (
                                <Field>
                                    <FieldLabel>
                                        {mark(
                                            'identity_document',
                                            'ID document',
                                        )}
                                    </FieldLabel>
                                    <p className="text-sm font-medium tabular-nums">
                                        {pickedDocument.label}
                                    </p>
                                </Field>
                            ) : (
                                <IdentityDocumentFields
                                    documents={card.documents}
                                    documentId={form.data.identity_document_id}
                                    identity={form.data.identity}
                                    onDocumentChange={(value) =>
                                        form.setData(
                                            'identity_document_id',
                                            value,
                                        )
                                    }
                                    onIdentityChange={(value) =>
                                        form.setData('identity', value)
                                    }
                                    identityTypes={identityTypes}
                                    countries={countries}
                                    errors={errors}
                                    label={mark(
                                        'identity_document',
                                        'ID document',
                                    )}
                                    word={word}
                                />
                            )}
                        </FieldGroup>
                    )}
                    {has('marketing_consent') && (
                        <Field orientation="horizontal">
                            <Switch
                                id="card-marketing_consent"
                                checked={form.data.marketing_consent}
                                onCheckedChange={(checked) =>
                                    form.setData('marketing_consent', checked)
                                }
                            />
                            <FieldLabel htmlFor="card-marketing_consent">
                                {word('I agree to receive offers')}
                            </FieldLabel>
                        </Field>
                    )}
                </section>

                <div className="flex flex-col gap-6">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <h4 className="text-sm font-medium">
                                {word('Terms')}
                            </h4>
                            {card.checklist.length > 1 && (
                                <div className="flex items-center gap-3">
                                    <span className="text-sm text-muted-foreground tabular-nums">
                                        {word(':ticked of :total confirmed')
                                            .replace(
                                                ':ticked',
                                                String(form.data.ticks.length),
                                            )
                                            .replace(
                                                ':total',
                                                String(card.checklist.length),
                                            )}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={toggleAll}
                                    >
                                        {word(
                                            allTicked
                                                ? 'Untick all'
                                                : 'Agree to all',
                                        )}
                                    </Button>
                                </div>
                            )}
                        </div>
                        <div className="max-h-96 overflow-y-auto rounded-lg border p-4">
                            <TermsPreview
                                body={card.termsText}
                                values={{}}
                                ticks={form.data.ticks}
                                onToggle={(position, checked) =>
                                    form.setData(
                                        'ticks',
                                        checked
                                            ? [...form.data.ticks, position]
                                            : form.data.ticks.filter(
                                                  (tick) => tick !== position,
                                              ),
                                    )
                                }
                            />
                        </div>
                    </section>

                    <section className="flex flex-col gap-3">
                        <h4 className="text-sm font-medium">
                            {word('Signature')}
                        </h4>
                        <FieldGroup className="grid gap-4 sm:grid-cols-2">
                            {text(
                                'signer_name',
                                'Name of signer',
                                null,
                                'text',
                                word('Type your full name'),
                            )}
                        </FieldGroup>
                        <SignatureField
                            onChange={(dataUrl) =>
                                form.setData('signature', dataUrl)
                            }
                            invalid={!!errors.signature}
                            labels={{
                                title: word('Signature'),
                                clear: word('Clear'),
                                fullScreen: word('Full screen'),
                                done: word('Done'),
                                cancel: word('Cancel'),
                            }}
                        />
                        <FieldError>{errors.signature}</FieldError>
                    </section>
                </div>
            </div>

            <div className="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-background py-3">
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
