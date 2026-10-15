import { Head, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import PaymentMethodController from '@/actions/App/Http/Controllers/Onboarding/PaymentMethodController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type PaymentMethod = App.Data.Onboarding.OnboardingPaymentMethodData;
type TypeOption = { value: string; label: string; requiresReference: boolean };
type Row = {
    id: number | null;
    key: string;
    name: string;
    type: string;
    type_label: string;
    active: boolean;
    requires_reference: boolean;
    is_editable: boolean;
};

const FORM_ID = 'payment-methods-form';

export default function PaymentMethods({
    paymentMethods,
    typeOptions,
    maxRows,
    locked,
    blockedBy,
}: {
    paymentMethods: PaymentMethod[];
    typeOptions: TypeOption[];
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const firstType = typeOptions[0];
    const form = useForm<{ rows: Row[] }>({
        rows: paymentMethods.map((method) => ({
            id: method.id,
            key: `saved-${method.id}`,
            name: method.name,
            type: method.type,
            type_label: method.typeLabel,
            active: method.active,
            requires_reference: method.requiresReference,
            is_editable: method.isEditable,
        })),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'payment_methods',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '5xl',
    });

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const addRow = () =>
        form.setData('rows', [
            ...rows,
            {
                id: null,
                key: `new-${Date.now()}-${rows.length}`,
                name: '',
                type: firstType.value,
                type_label: firstType.label,
                active: true,
                requires_reference: firstType.requiresReference,
                is_editable: true,
            },
        ]);

    const removeUnsaved = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            rows: data.rows.map((row) => ({
                id: row.id,
                name: row.name,
                type: row.id === null ? row.type : null,
                active: row.active,
                requires_reference: row.requires_reference,
            })),
        }));
        form.put(PaymentMethodController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    return (
        <>
            <Head title="Payment methods" />
            <Heading
                title="Payment methods"
                description="How do your guests pay?"
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>On</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Ask for ref. no.</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => {
                                const fieldError = (name: string) =>
                                    errors[`rows.${index}.${name}`];
                                const label = row.name || `method ${index + 1}`;

                                return (
                                    <TableRow
                                        key={row.key}
                                        className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3"
                                    >
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                On
                                            </span>
                                            <div className="pt-2">
                                                <Switch
                                                    aria-label={`Accept ${label}`}
                                                    checked={row.active}
                                                    disabled={!row.is_editable}
                                                    onCheckedChange={(value) =>
                                                        setRow(index, {
                                                            active: value,
                                                        })
                                                    }
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Name
                                            </span>
                                            <Input
                                                aria-label={`Name of payment method ${index + 1}`}
                                                placeholder="e.g. DuitNow QR"
                                                maxLength={100}
                                                value={row.name}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError('name')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('name')}
                                            </FieldError>
                                            {!row.is_editable && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    City ledger turns on
                                                    automatically when you add a
                                                    company with Direct bill.
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Type
                                            </span>
                                            {row.id === null ? (
                                                <Select
                                                    items={typeOptions}
                                                    value={row.type}
                                                    onValueChange={(value) => {
                                                        const option =
                                                            typeOptions.find(
                                                                (item) =>
                                                                    item.value ===
                                                                    value,
                                                            );

                                                        setRow(index, {
                                                            type: value as string,
                                                            type_label:
                                                                option?.label ??
                                                                row.type_label,
                                                            requires_reference:
                                                                option?.requiresReference ??
                                                                row.requires_reference,
                                                        });
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        aria-label={`Type of payment method ${index + 1}`}
                                                        className="w-full"
                                                        aria-invalid={
                                                            !!fieldError('type')
                                                        }
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {typeOptions.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <p className="pt-2 text-sm">
                                                    {row.type_label}
                                                </p>
                                            )}
                                            <FieldError>
                                                {fieldError('type')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Ask for ref. no.
                                            </span>
                                            <div className="pt-2.5">
                                                {row.is_editable ? (
                                                    <Checkbox
                                                        aria-label={`Ask for a reference number on ${label}`}
                                                        checked={
                                                            row.requires_reference
                                                        }
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                requires_reference:
                                                                    value,
                                                            })
                                                        }
                                                    />
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        –
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                            <div className="flex justify-end">
                                                {row.id === null && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Remove ${label}`}
                                                        onClick={() =>
                                                            removeUnsaved(index)
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={rows.length >= maxRows}
                                            focusableWhenDisabled
                                            onClick={addRow}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add payment method
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                <p className="text-sm text-muted-foreground">
                    After you go live, you can add card fees and change the
                    order in Settings, under Payment methods.
                </p>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Payment methods"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
