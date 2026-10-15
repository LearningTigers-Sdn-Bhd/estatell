import { Info, Plus, Trash2 } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type LicenceRow = {
    id: number | null;
    issuer: string;
    number: string;
    expires_on: string;
    show_on_documents: boolean;
};

export type BusinessValues = {
    legal_name: string;
    registration_number: string;
    tin: string;
    licences: LicenceRow[];
};

export function toLicenceRows(
    licences: App.Data.Property.PropertyLicenceData[],
): LicenceRow[] {
    return licences.map((licence) => ({
        id: licence.id,
        issuer: licence.issuer,
        number: licence.number,
        expires_on: licence.expiresOn ?? '',
        show_on_documents: licence.showOnDocuments,
    }));
}

/**
 * The optional Business details group of the Basics step: registered name, registration number, TIN and the
 * licences table. The labels follow the country profile. The licence rows keep their `show_on_documents` flag, which
 * is chosen in Settings after go-live.
 */
export function BusinessDetailsFields({
    values,
    errors,
    registrationNumberLabel,
    tinLabel,
    maxLicences,
    onChange,
}: {
    values: BusinessValues;
    errors: Record<string, string | undefined>;
    registrationNumberLabel: string;
    tinLabel: string;
    maxLicences: number;
    onChange: <K extends keyof BusinessValues>(
        key: K,
        value: BusinessValues[K],
    ) => void;
}) {
    const rows = values.licences;

    const setRow = (index: number, changes: Partial<LicenceRow>) => {
        onChange(
            'licences',
            rows.map((row, position) =>
                position === index ? { ...row, ...changes } : row,
            ),
        );
    };

    return (
        <section className="flex flex-col gap-5" aria-labelledby="business">
            <header className="flex items-center gap-1">
                <h3 id="business" className="text-base font-medium">
                    Business details (optional)
                </h3>
                <Popover>
                    <PopoverTrigger
                        render={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="About business details"
                            />
                        }
                    >
                        <Info />
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-80">
                        <PopoverDescription>
                            These can appear on invoices, receipts and the
                            registration card. After you go live, you can choose
                            which ones to show in Settings, under Printing.
                        </PopoverDescription>
                    </PopoverContent>
                </Popover>
            </header>
            <div className="grid gap-5 md:grid-cols-2">
                <Field className="md:col-span-2">
                    <FieldLabel htmlFor="legal_name">
                        Registered business name
                    </FieldLabel>
                    <Input
                        id="legal_name"
                        value={values.legal_name}
                        maxLength={255}
                        placeholder="e.g. Seaview Hospitality Sdn Bhd"
                        onChange={(event) =>
                            onChange('legal_name', event.target.value)
                        }
                        aria-invalid={!!errors.legal_name}
                    />
                    <FieldError>{errors.legal_name}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="registration_number">
                        {registrationNumberLabel}
                    </FieldLabel>
                    <Input
                        id="registration_number"
                        value={values.registration_number}
                        maxLength={100}
                        onChange={(event) =>
                            onChange('registration_number', event.target.value)
                        }
                        aria-invalid={!!errors.registration_number}
                    />
                    <FieldError>{errors.registration_number}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="tin">{tinLabel}</FieldLabel>
                    <Input
                        id="tin"
                        value={values.tin}
                        maxLength={50}
                        onChange={(event) =>
                            onChange('tin', event.target.value)
                        }
                        aria-invalid={!!errors.tin}
                    />
                    <FieldError>{errors.tin}</FieldError>
                </Field>
            </div>

            <div className="flex flex-col gap-2">
                <h4 className="text-sm font-medium">Licences</h4>
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Issued by</TableHead>
                                <TableHead>Licence no.</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="h-16 text-center text-muted-foreground"
                                    >
                                        No licences yet
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((row, index) => (
                                <TableRow key={row.id ?? `new-${index}`}>
                                    <TableCell className="min-w-44 align-top">
                                        <Input
                                            aria-label={`Issued by, licence ${index + 1}`}
                                            placeholder="e.g. Dewan Bandaraya Kota Kinabalu"
                                            maxLength={150}
                                            value={row.issuer}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    issuer: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `licences.${index}.issuer`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`licences.${index}.issuer`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="min-w-32 align-top">
                                        <Input
                                            aria-label={`Licence number, licence ${index + 1}`}
                                            maxLength={50}
                                            value={row.number}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    number: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `licences.${index}.number`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`licences.${index}.number`]}
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
                                            className="w-36"
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
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove licence ${index + 1}`}
                                                onClick={() =>
                                                    onChange(
                                                        'licences',
                                                        rows.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                    )
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
                                <TableCell colSpan={4}>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={
                                                rows.length >= maxLicences
                                            }
                                            onClick={() =>
                                                onChange('licences', [
                                                    ...rows,
                                                    {
                                                        id: null,
                                                        issuer: '',
                                                        number: '',
                                                        expires_on: '',
                                                        show_on_documents: false,
                                                    },
                                                ])
                                            }
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
                <FieldError>{errors.licences}</FieldError>
            </div>
        </section>
    );
}
