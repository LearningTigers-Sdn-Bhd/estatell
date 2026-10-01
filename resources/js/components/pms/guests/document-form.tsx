import { Link, useForm } from '@inertiajs/react';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index, scan, store, update } from '@/routes/guests/documents';

type Guest = App.Data.Guests.GuestData;
type Document = App.Data.Guests.GuestIdentityDocumentData;

type Props = {
    guest: Guest;
    document: Document | null;
    types: EnumOption[];
    countries: EnumOption[];
};

const NUMBER_PLACEHOLDERS: Record<string, string> = {
    mykad: '900101-14-1234',
    passport: 'A12345678',
    other: 'Document number',
};

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

/**
 * The full-page ID document form, for adding and editing. Scans are uploads, so it is a full page.
 * A new file replaces the old scan. Tick "Remove" to delete a scan without a replacement.
 */
export function DocumentForm({ guest, document, types, countries }: Props) {
    const form = useForm({
        type: document?.type ?? 'mykad',
        number: document?.number ?? '',
        issuing_country_code:
            document?.issuingCountryCode ?? guest.nationalityCode ?? 'MY',
        expires_on: document?.expiresOn ?? '',
        front_scan: null as File | null,
        back_scan: null as File | null,
        remove_front_scan: false,
        remove_back_scan: false,
    });
    const guard = useUnsavedChanges(form.isDirty);
    const { data, errors } = form;

    const save = (onSaved?: () => void) => {
        const options = {
            forceFormData: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        };

        if (document) {
            form.transform((fields) => ({ ...fields, _method: 'put' }));
            form.post(
                update.url({ guest: guest.id, document: document.id }),
                options,
            );
        } else {
            form.post(store.url({ guest: guest.id }), options);
        }
    };

    const scanField = (
        side: 'front' | 'back',
        label: string,
        existing: boolean,
    ) => {
        const field = `${side}_scan` as const;
        const removeField = `remove_${side}_scan` as const;

        return (
            <Field data-invalid={!!errors[field]}>
                <FieldLabel htmlFor={field}>{label}</FieldLabel>
                <Input
                    id={field}
                    type="file"
                    accept={ACCEPT}
                    onChange={(event) =>
                        form.setData(field, event.target.files?.[0] ?? null)
                    }
                    aria-invalid={!!errors[field]}
                />
                <FieldDescription>
                    JPEG, PNG, WebP or PDF, up to 10 MB.
                </FieldDescription>
                {document && existing && (
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <a
                            href={scan.url({
                                guest: guest.id,
                                document: document.id,
                                scan: side,
                            })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline underline-offset-4"
                        >
                            View current scan
                        </a>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={data[removeField]}
                                onCheckedChange={(checked) =>
                                    form.setData(removeField, checked === true)
                                }
                            />
                            Remove it
                        </label>
                    </div>
                )}
                <FieldError>{errors[field]}</FieldError>
            </Field>
        );
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex max-w-3xl flex-col gap-8"
        >
            <FieldGroup>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors.type}>
                        <FieldLabel htmlFor="type">Document type</FieldLabel>
                        <EnumSelect
                            id="type"
                            options={types}
                            value={data.type}
                            onValueChange={(value) =>
                                form.setData('type', value)
                            }
                            aria-invalid={!!errors.type}
                            className="w-full"
                        />
                        <FieldError>{errors.type}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.number}>
                        <FieldLabel htmlFor="number">Number</FieldLabel>
                        <Input
                            id="number"
                            value={data.number}
                            maxLength={40}
                            autoComplete="off"
                            placeholder={NUMBER_PLACEHOLDERS[data.type]}
                            onChange={(event) =>
                                form.setData('number', event.target.value)
                            }
                            aria-invalid={!!errors.number}
                        />
                        <FieldDescription>
                            Spaces and dashes are ignored.
                        </FieldDescription>
                        <FieldError>{errors.number}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.issuing_country_code}>
                        <FieldLabel htmlFor="issuing_country_code">
                            Issuing country
                        </FieldLabel>
                        <CountrySelect
                            id="issuing_country_code"
                            options={countries}
                            value={data.issuing_country_code}
                            onValueChange={(value) =>
                                form.setData('issuing_country_code', value)
                            }
                            invalid={!!errors.issuing_country_code}
                        />
                        <FieldError>{errors.issuing_country_code}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.expires_on}>
                        <FieldLabel htmlFor="expires_on">
                            Expiry date
                        </FieldLabel>
                        <Input
                            id="expires_on"
                            type="date"
                            value={data.expires_on}
                            maxLength={10}
                            placeholder="YYYY-MM-DD"
                            onChange={(event) =>
                                form.setData('expires_on', event.target.value)
                            }
                            aria-invalid={!!errors.expires_on}
                        />
                        <FieldError>{errors.expires_on}</FieldError>
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    {scanField('front', 'Front scan', !!document?.hasFrontScan)}
                    {scanField('back', 'Back scan', !!document?.hasBackScan)}
                </div>
            </FieldGroup>

            <footer className="flex justify-end gap-2">
                <Link
                    href={index.url({ guest: guest.id })}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Cancel
                </Link>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {document ? 'Save document' : 'Add document'}
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name="this document"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
