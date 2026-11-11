import { useForm } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
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
import { Input } from '@/components/ui/input';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { eInvoice } from '@/routes/reservations/folios';

const MALAYSIA_STATES = [
    { value: '01', label: '01 - Johor' },
    { value: '02', label: '02 - Kedah' },
    { value: '03', label: '03 - Kelantan' },
    { value: '04', label: '04 - Melaka' },
    { value: '05', label: '05 - Negeri Sembilan' },
    { value: '06', label: '06 - Pahang' },
    { value: '07', label: '07 - Pulau Pinang' },
    { value: '08', label: '08 - Perak' },
    { value: '09', label: '09 - Perlis' },
    { value: '10', label: '10 - Selangor' },
    { value: '11', label: '11 - Terengganu' },
    { value: '12', label: '12 - Sabah' },
    { value: '13', label: '13 - Sarawak' },
    { value: '14', label: '14 - Wilayah Persekutuan Kuala Lumpur' },
    { value: '15', label: '15 - Wilayah Persekutuan Labuan' },
    { value: '16', label: '16 - Wilayah Persekutuan Putrajaya' },
    { value: '17', label: '17 - Not applicable (outside Malaysia)' },
];

const ID_TYPES = [
    { value: 'NRIC', label: 'Malaysian NRIC (MyKad)' },
    { value: 'PASSPORT', label: 'Passport' },
    { value: 'BRN', label: 'Business Registration (BRN)' },
    { value: 'ARMY', label: 'Military / Police ID' },
];

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
    const initialCountry = (buyer?.country_code as string | undefined) ?? 'MYS';
    const isInitiallyForeign =
        initialCountry !== 'MYS' && initialCountry !== 'MY';

    const form = useForm({
        requested_by_guest: true,
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
    const isForeign =
        data.buyer_snapshot.country_code !== 'MYS' &&
        data.buyer_snapshot.country_code !== 'MY';

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
                        Guest / Company Name *
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
                            Identification Type *
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
                            ID / Passport Number *
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
                                ? 'Malaysian TIN (Optional for Foreigners)'
                                : 'Tax Identification Number (TIN) *'}
                        </FieldLabel>
                        <Input
                            id="einvoice-buyer-tin"
                            value={data.buyer_snapshot.tin}
                            onChange={(e) =>
                                updateSnapshot('tin', e.target.value)
                            }
                            placeholder={
                                isForeign
                                    ? 'Leave blank for foreign TIN EI00000000020'
                                    : 'e.g. IG1234567890 or C1234567890'
                            }
                        />
                        <FieldDescription>
                            {isForeign
                                ? 'Foreign buyers without a Malaysian TIN automatically use LHDN general foreign TIN (EI00000000020).'
                                : 'Required for individual e-invoices issued to Malaysian citizens.'}
                        </FieldDescription>
                        <FieldError>{errors['buyer_snapshot.tin']}</FieldError>
                    </Field>

                    <Field
                        data-invalid={!!errors['buyer_snapshot.country_code']}
                    >
                        <FieldLabel htmlFor="einvoice-country-code">
                            Country (ISO Alpha-3) *
                        </FieldLabel>
                        <Input
                            id="einvoice-country-code"
                            value={data.buyer_snapshot.country_code}
                            onChange={(e) => {
                                const country = e.target.value.toUpperCase();
                                updateSnapshot('country_code', country);
                                if (country !== 'MYS' && country !== 'MY') {
                                    updateSnapshot('state_code', '17');
                                }
                            }}
                            maxLength={3}
                            placeholder="MYS"
                            required
                        />
                        <FieldDescription>
                            Use 3-letter ISO code: MYS (Malaysia), SGP
                            (Singapore), AUS (Australia), GBR (UK).
                        </FieldDescription>
                        <FieldError>
                            {errors['buyer_snapshot.country_code']}
                        </FieldError>
                    </Field>
                </FieldGroup>

                <Field
                    data-invalid={!!errors['buyer_snapshot.sst_registration']}
                >
                    <FieldLabel htmlFor="einvoice-sst">
                        SST Registration (Optional)
                    </FieldLabel>
                    <Input
                        id="einvoice-sst"
                        value={data.buyer_snapshot.sst_registration}
                        onChange={(e) =>
                            updateSnapshot('sst_registration', e.target.value)
                        }
                        placeholder="For registered business entities"
                    />
                    <FieldError>
                        {errors['buyer_snapshot.sst_registration']}
                    </FieldError>
                </Field>
            </FieldSet>

            <FieldSet>
                <FieldLegend>Billing Address</FieldLegend>
                <FieldDescription>
                    LHDN requires the buyer city and state on all e-invoices.
                </FieldDescription>

                <Field data-invalid={!!errors['buyer_snapshot.address_line1']}>
                    <FieldLabel htmlFor="einvoice-address-1">
                        Street Address
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
                            State / Territory *
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
                <FieldLegend>Contact Delivery (Optional)</FieldLegend>
                <FieldDescription>
                    Contact details for e-invoice notification and delivery.
                </FieldDescription>

                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors['buyer_snapshot.email']}>
                        <FieldLabel htmlFor="einvoice-email">
                            Email Address
                        </FieldLabel>
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
                        <FieldLabel htmlFor="einvoice-phone">
                            Phone Number
                        </FieldLabel>
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
