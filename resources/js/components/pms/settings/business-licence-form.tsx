import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update } from '@/routes/settings/business';

type Business = App.Data.Property.PropertyBusinessData;

type Row = {
    id: number | null;
    issuer: string;
    number: string;
    expires_on: string;
    show_on_documents: boolean;
};

function toRows(licences: Business['licences']): Row[] {
    return licences.map((licence) => ({
        id: licence.id,
        issuer: licence.issuer,
        number: licence.number,
        expires_on: licence.expiresOn ?? '',
        show_on_documents: licence.showOnDocuments,
    }));
}

export function BusinessLicenceForm({
    business,
    maxLicences,
}: {
    business: Business;
    maxLicences: number;
}) {
    const form = useForm<{
        legal_name: string;
        registration_number: string;
        tin: string;
        licences: Row[];
    }>({
        legal_name: business.legalName ?? '',
        registration_number: business.registrationNumber ?? '',
        tin: business.tin ?? '',
        licences: toRows(business.licences),
    });
    const rows = form.data.licences;
    const errors = form.errors as Record<string, string | undefined>;

    const setRow = (index: number, values: Partial<Row>) => {
        form.setData(
            'licences',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const addRow = () => {
        form.setData('licences', [
            ...rows,
            {
                id: null,
                issuer: '',
                number: '',
                expires_on: '',
                show_on_documents: false,
            },
        ]);
    };

    const removeRow = (index: number) => {
        form.setData(
            'licences',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <section className="flex flex-col gap-5">
                <header className="space-y-1">
                    <h3 className="text-base font-medium">Business details</h3>
                    <p className="text-sm text-muted-foreground">
                        The registered business name and numbers. They can print
                        on receipts, invoices and reports.
                    </p>
                </header>
                <div className="grid gap-5 md:grid-cols-2">
                    <Field className="md:col-span-2">
                        <FieldLabel htmlFor="legal_name">
                            Registered business name (optional)
                        </FieldLabel>
                        <Input
                            id="legal_name"
                            value={form.data.legal_name}
                            maxLength={255}
                            placeholder="e.g. Seaview Hospitality Sdn Bhd"
                            onChange={(event) =>
                                form.setData('legal_name', event.target.value)
                            }
                            aria-invalid={!!errors.legal_name}
                        />
                        <FieldError>{errors.legal_name}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="registration_number">
                            {`${business.registrationNumberLabel} (optional)`}
                        </FieldLabel>
                        <Input
                            id="registration_number"
                            value={form.data.registration_number}
                            maxLength={100}
                            placeholder="e.g. 202301012345"
                            onChange={(event) =>
                                form.setData(
                                    'registration_number',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!errors.registration_number}
                        />
                        <FieldError>{errors.registration_number}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="tin">
                            {`${business.tinLabel} (optional)`}
                        </FieldLabel>
                        <Input
                            id="tin"
                            value={form.data.tin}
                            maxLength={50}
                            placeholder="e.g. C1234567890"
                            onChange={(event) =>
                                form.setData('tin', event.target.value)
                            }
                            aria-invalid={!!errors.tin}
                        />
                        <FieldError>{errors.tin}</FieldError>
                    </Field>
                </div>
            </section>

            <section className="flex flex-col gap-5">
                <header className="space-y-1">
                    <h3 className="text-base font-medium">Licences</h3>
                    <p className="text-sm text-muted-foreground">
                        Business licences and registrations, for example a local
                        council licence or a MOTAC registration. Tick Print to
                        show a licence on documents.
                    </p>
                </header>

                {
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Issued by</TableHead>
                                    <TableHead>Licence no.</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead>Print</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="h-16 text-center text-muted-foreground"
                                        >
                                            No licences yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((row, index) => (
                                    <TableRow key={row.id ?? `new-${index}`}>
                                        <TableCell className="align-top">
                                            <Input
                                                aria-label={`Issued by, licence ${index + 1}`}
                                                placeholder="e.g. Dewan Bandaraya Kota Kinabalu"
                                                maxLength={150}
                                                value={row.issuer}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        issuer: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `licences.${index}.issuer`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {
                                                    errors[
                                                        `licences.${index}.issuer`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <Input
                                                aria-label={`Licence number, licence ${index + 1}`}
                                                placeholder="e.g. LIC-2026-00123"
                                                maxLength={50}
                                                value={row.number}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        number: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `licences.${index}.number`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {
                                                    errors[
                                                        `licences.${index}.number`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <DatePicker
                                                value={row.expires_on}
                                                onChange={(value) =>
                                                    setRow(index, {
                                                        expires_on: value,
                                                    })
                                                }
                                                placeholder="No expiry"
                                                className="w-40"
                                                aria-invalid={
                                                    !!errors[
                                                        `licences.${index}.expires_on`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {
                                                    errors[
                                                        `licences.${index}.expires_on`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <span className="flex h-9 items-center">
                                                <Checkbox
                                                    aria-label={`Print licence ${index + 1} on documents`}
                                                    checked={
                                                        row.show_on_documents
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setRow(index, {
                                                            show_on_documents:
                                                                checked,
                                                        })
                                                    }
                                                />
                                            </span>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <div className="flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove licence ${index + 1}`}
                                                    onClick={() =>
                                                        removeRow(index)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={5}>
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    rows.length >= maxLicences
                                                }
                                                onClick={addRow}
                                            >
                                                <Plus data-icon="inline-start" />
                                                Add licence
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                }
                <FieldError>{errors.licences}</FieldError>
            </section>

            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save business details
                </Button>
            </footer>
        </form>
    );
}
