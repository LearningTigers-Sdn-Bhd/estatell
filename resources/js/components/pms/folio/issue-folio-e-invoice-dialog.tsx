import { useForm, useHttp, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { check as checkTaxNumber } from '@/routes/finance/e-invoices/tax-number';
import { eInvoice } from '@/routes/reservations/folios';

const MALAYSIA_STATES = [
    { value: '01', label: 'Johor' },
    { value: '02', label: 'Kedah' },
    { value: '03', label: 'Kelantan' },
    { value: '04', label: 'Melaka' },
    { value: '05', label: 'Negeri Sembilan' },
    { value: '06', label: 'Pahang' },
    { value: '07', label: 'Pulau Pinang' },
    { value: '08', label: 'Perak' },
    { value: '09', label: 'Perlis' },
    { value: '10', label: 'Selangor' },
    { value: '11', label: 'Terengganu' },
    { value: '12', label: 'Sabah' },
    { value: '13', label: 'Sarawak' },
    { value: '14', label: 'Wilayah Persekutuan Kuala Lumpur' },
    { value: '15', label: 'Wilayah Persekutuan Labuan' },
    { value: '16', label: 'Wilayah Persekutuan Putrajaya' },
    { value: '17', label: 'Not applicable (outside Malaysia)' },
];

const ID_TYPES = [
    { value: 'NRIC', label: 'Malaysian IC (MyKad)' },
    { value: 'PASSPORT', label: 'Passport' },
    { value: 'BRN', label: 'Company registration number' },
    { value: 'ARMY', label: 'Army or police ID' },
];

type TaxNumberAnswer = {
    result: 'match' | 'found' | 'not_found' | 'unavailable' | 'too_many';
    tin: string | null;
    message: string;
};

/**
 * Asks LHDN whether the tax number belongs to the ID before sending, so a mismatch is fixed here instead of coming
 * back as a rejection. When LHDN holds another tax number for the ID, one press puts it in the form.
 */
function TaxNumberCheck({
    tin,
    idType,
    idValue,
    onUse,
}: {
    tin: string;
    idType: string;
    idValue: string;
    onUse: (tin: string) => void;
}) {
    const http = useHttp<
        { tin: string; id_type: string; id_value: string },
        TaxNumberAnswer
    >({ tin: '', id_type: '', id_value: '' });
    const [answer, setAnswer] = useState<{
        for: string;
        value: TaxNumberAnswer;
    } | null>(null);
    const [failed, setFailed] = useState(false);
    const asked = `${tin}|${idType}|${idValue}`;
    const current = answer?.for === asked ? answer.value : null;

    const ask = () => {
        setFailed(false);
        http.transform(() => ({ tin, id_type: idType, id_value: idValue }));
        http.post(checkTaxNumber.url())
            .then((value) => setAnswer({ for: asked, value }))
            .catch(() => setFailed(true));
    };

    return (
        <div className="flex flex-col gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={ask}
                disabled={http.processing || idValue.trim() === ''}
                focusableWhenDisabled
            >
                {http.processing ? (
                    <Spinner data-icon="inline-start" />
                ) : (
                    <ShieldCheck data-icon="inline-start" />
                )}
                Check with LHDN
            </Button>
            {idValue.trim() === '' && (
                <p className="text-sm text-muted-foreground">
                    Enter the ID number to check the tax number with LHDN.
                </p>
            )}
            {failed && (
                <p className="text-sm text-destructive">
                    The check did not go through. Try again.
                </p>
            )}
            {current && (
                <Alert
                    variant={
                        current.result === 'match' ? 'default' : 'destructive'
                    }
                >
                    {current.result === 'match' ? (
                        <CircleCheck className="text-success" />
                    ) : (
                        <CircleAlert />
                    )}
                    <AlertDescription className="flex flex-col gap-2">
                        <p>{current.message}</p>
                        {current.result === 'found' && current.tin && (
                            <Button
                                type="button"
                                size="sm"
                                className="self-start"
                                onClick={() => onUse(current.tin!)}
                            >
                                Use {current.tin}
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: App.Data.Folio.FolioData;
};

function IssueFolioEInvoiceForm({
    open,
    onOpenChange,
    reservationId,
    folio,
}: Props) {
    const buyer = folio.eInvoiceBuyer ?? null;
    const countries =
        usePage<{ eInvoiceCountries?: EnumOption[] }>().props
            .eInvoiceCountries ?? [];
    const initialCountry = (buyer?.country_code as string | undefined) ?? 'MY';
    const isInitiallyForeign = initialCountry !== 'MY';
    // A company payer's details live on the company record, not a guest profile.
    const isGuestBuyer = (buyer?.id_type as string | undefined) !== 'BRN';

    const form = useForm({
        requested_by_guest: true,
        save_to_guest: isGuestBuyer,
        buyer_snapshot: {
            name: (buyer?.name as string | undefined) ?? folio.name,
            tin: (buyer?.tin as string | undefined) ?? '',
            id_type:
                (buyer?.id_type as string | undefined) ??
                (isInitiallyForeign ? 'PASSPORT' : 'NRIC'),
            id_value:
                (buyer?.id_value as string | undefined) === 'NA'
                    ? ''
                    : ((buyer?.id_value as string | undefined) ?? ''),
            email: (buyer?.email as string | undefined) ?? '',
            phone: (buyer?.phone as string | undefined) ?? '',
            address_line1:
                (buyer?.address_line1 as string | undefined) === 'NA'
                    ? ''
                    : ((buyer?.address_line1 as string | undefined) ?? ''),
            address_line2: (buyer?.address_line2 as string | undefined) ?? '',
            city: (buyer?.city as string | undefined) ?? '',
            postal_code: (buyer?.postal_code as string | undefined) ?? '',
            state_code:
                (buyer?.state_code as string | undefined) ??
                (isInitiallyForeign ? '17' : '12'),
            country_code: initialCountry,
            sst_registration:
                (buyer?.sst_registration as string | undefined) ?? '',
        },
    });

    const { data, errors } = form;
    const rejection = folio.eInvoiceRejection;
    const isForeign = data.buyer_snapshot.country_code !== 'MY';

    const submit = () => {
        form.post(
            eInvoice.url({
                reservation: reservationId,
                folio: Number(folio.key),
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    const updateSnapshot = (
        key: keyof typeof data.buyer_snapshot,
        value: string,
    ) => {
        form.setData('buyer_snapshot', {
            ...data.buyer_snapshot,
            [key]: value,
        });
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={
                rejection.length > 0
                    ? 'Fix and send e-invoice again'
                    : 'Issue e-invoice'
            }
            description={`Sends folio ${folio.number ?? folio.name} to LHDN as the guest's own e-invoice. Check the details with the guest first: LHDN rejects a tax number that does not match the IC or company registration.`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Send to LHDN"
            processing={form.processing}
        >
            {rejection.length > 0 && (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertTitle>LHDN rejected the last one</AlertTitle>
                    <AlertDescription>
                        <ul className="flex flex-col gap-2">
                            {rejection.map((reason) => (
                                <li
                                    key={reason.message}
                                    className="flex flex-col gap-0.5"
                                >
                                    <strong>{reason.message}</strong>
                                    <span>{reason.fix}</span>
                                </li>
                            ))}
                        </ul>
                        <p>
                            The details below are what was sent. Correct them
                            and send again.
                        </p>
                    </AlertDescription>
                </Alert>
            )}
            <FieldSet>
                <FieldLegend>Billed to</FieldLegend>
                <FieldDescription>
                    The person or company named on the e-invoice.
                </FieldDescription>

                <Field data-invalid={!!errors['buyer_snapshot.name']}>
                    <FieldLabel htmlFor="einvoice-buyer-name">
                        Name on the e-invoice *
                    </FieldLabel>
                    <Input
                        id="einvoice-buyer-name"
                        value={data.buyer_snapshot.name}
                        onChange={(e) => updateSnapshot('name', e.target.value)}
                        placeholder="Full name as per IC or Passport"
                        required
                    />
                    <FieldError>{errors['buyer_snapshot.name']}</FieldError>
                </Field>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors['buyer_snapshot.id_type']}>
                        <FieldLabel htmlFor="einvoice-id-type">
                            ID type *
                        </FieldLabel>
                        <EnumSelect
                            id="einvoice-id-type"
                            options={ID_TYPES}
                            value={data.buyer_snapshot.id_type}
                            onValueChange={(val) =>
                                updateSnapshot('id_type', val)
                            }
                            className="w-full"
                        />
                        <FieldError>
                            {errors['buyer_snapshot.id_type']}
                        </FieldError>
                    </Field>

                    <Field data-invalid={!!errors['buyer_snapshot.id_value']}>
                        <FieldLabel htmlFor="einvoice-id-value">
                            ID number *
                        </FieldLabel>
                        <Input
                            id="einvoice-id-value"
                            value={data.buyer_snapshot.id_value}
                            onChange={(e) =>
                                updateSnapshot('id_value', e.target.value)
                            }
                            placeholder={
                                data.buyer_snapshot.id_type === 'PASSPORT'
                                    ? 'Passport number'
                                    : '900101-12-1234'
                            }
                            required
                        />
                        <FieldError>
                            {errors['buyer_snapshot.id_value']}
                        </FieldError>
                    </Field>
                </FieldGroup>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors['buyer_snapshot.tin']}>
                        <FieldLabel htmlFor="einvoice-buyer-tin">
                            {isForeign
                                ? 'Malaysian tax number (optional)'
                                : 'Tax number (TIN) *'}
                        </FieldLabel>
                        <Input
                            id="einvoice-buyer-tin"
                            value={data.buyer_snapshot.tin}
                            onChange={(e) =>
                                updateSnapshot('tin', e.target.value)
                            }
                            placeholder={
                                isForeign
                                    ? 'Leave empty if none'
                                    : 'e.g. IG1234567890 or C1234567890'
                            }
                        />
                        <FieldDescription>
                            {isForeign
                                ? 'Leave it empty if the guest has none. LHDN accepts a foreign guest without one.'
                                : 'Starts with IG for a person or C for a company. Use Check with LHDN if unsure.'}
                        </FieldDescription>
                        <FieldError>{errors['buyer_snapshot.tin']}</FieldError>
                    </Field>

                    <Field
                        data-invalid={!!errors['buyer_snapshot.country_code']}
                    >
                        <FieldLabel htmlFor="einvoice-country-code">
                            Country *
                        </FieldLabel>
                        <CountrySelect
                            id="einvoice-country-code"
                            value={data.buyer_snapshot.country_code}
                            options={countries}
                            invalid={!!errors['buyer_snapshot.country_code']}
                            onValueChange={(country) =>
                                form.setData('buyer_snapshot', {
                                    ...data.buyer_snapshot,
                                    country_code: country,
                                    // A buyer outside Malaysia has no Malaysian state.
                                    ...(country !== 'MY'
                                        ? { state_code: '17' }
                                        : data.buyer_snapshot.state_code ===
                                            '17'
                                          ? { state_code: '' }
                                          : {}),
                                })
                            }
                        />
                        <FieldError>
                            {errors['buyer_snapshot.country_code']}
                        </FieldError>
                    </Field>
                </FieldGroup>

                <TaxNumberCheck
                    tin={data.buyer_snapshot.tin}
                    idType={data.buyer_snapshot.id_type}
                    idValue={data.buyer_snapshot.id_value}
                    onUse={(tin) => updateSnapshot('tin', tin)}
                />

                {isGuestBuyer && (
                    <Field orientation="horizontal">
                        <Checkbox
                            id="einvoice-save-to-guest"
                            checked={data.save_to_guest}
                            onCheckedChange={(checked) =>
                                form.setData('save_to_guest', checked === true)
                            }
                        />
                        <FieldLabel
                            htmlFor="einvoice-save-to-guest"
                            className="font-normal"
                        >
                            Save the tax number and ID to the guest profile when
                            LHDN accepts it
                        </FieldLabel>
                    </Field>
                )}

                <Field
                    data-invalid={!!errors['buyer_snapshot.sst_registration']}
                >
                    <FieldLabel htmlFor="einvoice-sst">
                        SST registration number (optional)
                    </FieldLabel>
                    <Input
                        id="einvoice-sst"
                        value={data.buyer_snapshot.sst_registration}
                        onChange={(e) =>
                            updateSnapshot('sst_registration', e.target.value)
                        }
                        placeholder="Only for a company registered for SST"
                    />
                    <FieldError>
                        {errors['buyer_snapshot.sst_registration']}
                    </FieldError>
                </Field>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Address</FieldLegend>
                <FieldDescription>
                    LHDN needs the city and state on every e-invoice.
                </FieldDescription>

                <Field data-invalid={!!errors['buyer_snapshot.address_line1']}>
                    <FieldLabel htmlFor="einvoice-address-1">
                        Street address
                    </FieldLabel>
                    <Input
                        id="einvoice-address-1"
                        value={data.buyer_snapshot.address_line1}
                        onChange={(e) =>
                            updateSnapshot('address_line1', e.target.value)
                        }
                        placeholder="Building, street, or residential address"
                    />
                    <FieldError>
                        {errors['buyer_snapshot.address_line1']}
                    </FieldError>
                </Field>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors['buyer_snapshot.city']}>
                        <FieldLabel htmlFor="einvoice-city">City *</FieldLabel>
                        <Input
                            id="einvoice-city"
                            value={data.buyer_snapshot.city}
                            onChange={(e) =>
                                updateSnapshot('city', e.target.value)
                            }
                            placeholder="Kota Kinabalu, Kuala Lumpur, etc."
                            required
                        />
                        <FieldError>{errors['buyer_snapshot.city']}</FieldError>
                    </Field>

                    <Field data-invalid={!!errors['buyer_snapshot.state_code']}>
                        <FieldLabel htmlFor="einvoice-state-code">
                            State *
                        </FieldLabel>
                        <EnumSelect
                            id="einvoice-state-code"
                            options={MALAYSIA_STATES}
                            value={data.buyer_snapshot.state_code}
                            onValueChange={(val) =>
                                updateSnapshot('state_code', val)
                            }
                            className="w-full"
                        />
                        <FieldError>
                            {errors['buyer_snapshot.state_code']}
                        </FieldError>
                    </Field>
                </FieldGroup>

                <Field data-invalid={!!errors['buyer_snapshot.postal_code']}>
                    <FieldLabel htmlFor="einvoice-postcode">
                        Postcode
                    </FieldLabel>
                    <Input
                        id="einvoice-postcode"
                        value={data.buyer_snapshot.postal_code}
                        onChange={(e) =>
                            updateSnapshot('postal_code', e.target.value)
                        }
                        placeholder="88000"
                    />
                    <FieldError>
                        {errors['buyer_snapshot.postal_code']}
                    </FieldError>
                </Field>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Contact (optional)</FieldLegend>
                <FieldDescription>
                    Used to email the guest their e-invoice.
                </FieldDescription>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors['buyer_snapshot.email']}>
                        <FieldLabel htmlFor="einvoice-email">Email</FieldLabel>
                        <Input
                            id="einvoice-email"
                            type="email"
                            value={data.buyer_snapshot.email}
                            onChange={(e) =>
                                updateSnapshot('email', e.target.value)
                            }
                            placeholder="guest@example.com"
                        />
                        <FieldError>
                            {errors['buyer_snapshot.email']}
                        </FieldError>
                    </Field>

                    <Field data-invalid={!!errors['buyer_snapshot.phone']}>
                        <FieldLabel htmlFor="einvoice-phone">Phone</FieldLabel>
                        <Input
                            id="einvoice-phone"
                            value={data.buyer_snapshot.phone}
                            onChange={(e) =>
                                updateSnapshot('phone', e.target.value)
                            }
                            placeholder="+60 12-345 6789"
                        />
                        <FieldError>
                            {errors['buyer_snapshot.phone']}
                        </FieldError>
                    </Field>
                </FieldGroup>
            </FieldSet>
        </FormOverlay>
    );
}

export function IssueFolioEInvoiceDialog(props: Props) {
    return (
        <IssueFolioEInvoiceForm key={useFreshOnOpen(props.open)} {...props} />
    );
}
