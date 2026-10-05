import { router, useForm } from '@inertiajs/react';
import { PenLine } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { SignatureDialog } from '@/components/pms/registration-card/signature-dialog';
import { TermsPreview } from '@/components/pms/registration-card/terms-preview';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { show, sign } from '@/routes/registration';

type Card = App.Data.Registration.RegistrationCardPageData;

type Options = {
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
};

/**
 * The open card: the guest's details by the Layout tab, the terms, one tick box for each checklist item, and the signature.
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
        identity_document_id: card.documents[0]
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
    const [signing, setSigning] = useState(false);
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
            show.url({ unitGuest: card.unitGuestId }),
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
    const displayFields = card.fields.filter((field) => field.value !== null);
    const newDocument = form.data.identity_document_id === '';

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
            <div className="flex justify-end">
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
                            {text('city', 'City', null)}
                            {text('state', 'State', null)}
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
                            {card.documents.length > 0 && (
                                <Field
                                    data-invalid={!!errors.identity_document_id}
                                >
                                    <FieldLabel htmlFor="card-identity_document_id">
                                        {mark(
                                            'identity_document',
                                            'ID document',
                                        )}
                                    </FieldLabel>
                                    <EnumSelect
                                        id="card-identity_document_id"
                                        options={[
                                            ...card.documents.map(
                                                (document) => ({
                                                    value: String(document.id),
                                                    label: document.label,
                                                }),
                                            ),
                                            {
                                                value: '',
                                                label: word(
                                                    'Enter a different document',
                                                ),
                                            },
                                        ]}
                                        value={form.data.identity_document_id}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'identity_document_id',
                                                value,
                                            )
                                        }
                                    />
                                    <FieldError>
                                        {errors.identity_document_id}
                                    </FieldError>
                                </Field>
                            )}
                            {newDocument && (
                                <>
                                    <Field
                                        data-invalid={!!errors['identity.type']}
                                    >
                                        <FieldLabel htmlFor="card-identity-type">
                                            {word('Document type')}
                                        </FieldLabel>
                                        <EnumSelect
                                            id="card-identity-type"
                                            options={identityTypes.map(
                                                (option) => ({
                                                    ...option,
                                                    label: word(option.label),
                                                }),
                                            )}
                                            value={form.data.identity.type}
                                            onValueChange={(value) =>
                                                form.setData('identity', {
                                                    ...form.data.identity,
                                                    type: value,
                                                })
                                            }
                                        />
                                        <FieldError>
                                            {errors['identity.type']}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={
                                            !!errors['identity.number']
                                        }
                                    >
                                        <FieldLabel htmlFor="card-identity-number">
                                            {word('Document number')}
                                        </FieldLabel>
                                        <Input
                                            id="card-identity-number"
                                            placeholder="900101-14-1234"
                                            value={form.data.identity.number}
                                            onChange={(event) =>
                                                form.setData('identity', {
                                                    ...form.data.identity,
                                                    number: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors['identity.number']
                                            }
                                        />
                                        <FieldError>
                                            {errors['identity.number']}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel htmlFor="card-identity-country">
                                            {word('Issuing country')}
                                        </FieldLabel>
                                        <CountrySelect
                                            id="card-identity-country"
                                            value={
                                                form.data.identity
                                                    .issuing_country_code
                                            }
                                            onValueChange={(value) =>
                                                form.setData('identity', {
                                                    ...form.data.identity,
                                                    issuing_country_code: value,
                                                })
                                            }
                                            options={countries}
                                        />
                                    </Field>
                                </>
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
                        <h4 className="text-sm font-medium">{word('Terms')}</h4>
                        <div className="h-64 overflow-y-auto rounded-lg border p-4">
                            <TermsPreview
                                body={card.termsText}
                                values={{}}
                                hideChecklist
                            />
                        </div>
                        <ul className="flex flex-col gap-2">
                            {card.checklist.map((item) => (
                                <li key={item.position}>
                                    <label className="flex items-start gap-2 text-sm">
                                        <Checkbox
                                            className="mt-0.5"
                                            checked={form.data.ticks.includes(
                                                item.position,
                                            )}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'ticks',
                                                    checked
                                                        ? [
                                                              ...form.data
                                                                  .ticks,
                                                              item.position,
                                                          ]
                                                        : form.data.ticks.filter(
                                                              (position) =>
                                                                  position !==
                                                                  item.position,
                                                          ),
                                                )
                                            }
                                        />
                                        <span>{item.text}</span>
                                    </label>
                                </li>
                            ))}
                        </ul>
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
                        {form.data.signature !== '' && (
                            <img
                                src={form.data.signature}
                                alt={word('Signature')}
                                className="h-24 w-fit rounded-lg border bg-white"
                            />
                        )}
                        <FieldError>{errors.signature}</FieldError>
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSigning(true)}
                            >
                                <PenLine data-icon="inline-start" />
                                {word('Sign here')}
                            </Button>
                        </div>
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

            <SignatureDialog
                open={signing}
                onOpenChange={setSigning}
                onDone={(dataUrl) => form.setData('signature', dataUrl)}
                labels={{
                    title: word('Signature'),
                    clear: word('Clear'),
                    done: word('Done'),
                    empty: word('Please sign in the box.'),
                }}
            />
        </form>
    );
}
