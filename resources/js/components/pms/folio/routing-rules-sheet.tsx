import { router, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { RemoveRoutingRuleDialog } from '@/components/pms/folio/remove-routing-rule-dialog';
import { FormError } from '@/components/pms/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { store } from '@/routes/reservations/routing-rules';

type Charges = 'category' | 'code' | 'all';

const ALL_UNITS = 'all';

const CHARGES: { value: Charges; label: string }[] = [
    { value: 'category', label: 'Category' },
    { value: 'code', label: 'Charge code' },
    { value: 'all', label: 'All charges' },
];

function AddRuleForm({
    reservationId,
    units,
    routing,
    chargeCodes,
}: {
    reservationId: number;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    routing: App.Data.Folio.RoutingRulesData;
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
}) {
    const form = useForm({
        reservation_unit_id: ALL_UNITS,
        charges: 'category' as Charges,
        category: '',
        transaction_code_id: '',
        target_folio_id: '',
    });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const ready =
        data.target_folio_id !== '' &&
        (data.charges !== 'category' || data.category !== '') &&
        (data.charges !== 'code' || data.transaction_code_id !== '');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            reservation_unit_id:
                values.reservation_unit_id === ALL_UNITS
                    ? null
                    : values.reservation_unit_id,
            charges: values.charges,
            category: values.charges === 'category' ? values.category : null,
            transaction_code_id:
                values.charges === 'code' ? values.transaction_code_id : null,
            target_folio_id: values.target_folio_id,
        }));
        form.post(store.url({ reservation: reservationId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => {
                form.reset();
                router.reload({ only: ['routingRules'] });
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
                <Field data-invalid={!!errors.reservation_unit_id}>
                    <FieldLabel htmlFor="routing-unit">Unit</FieldLabel>
                    <EnumSelect
                        id="routing-unit"
                        options={[
                            { value: ALL_UNITS, label: 'All units' },
                            ...units.map((unit) => ({
                                value: String(unit.id),
                                label: unit.label,
                            })),
                        ]}
                        value={data.reservation_unit_id}
                        onValueChange={(value) =>
                            form.setData('reservation_unit_id', value)
                        }
                        aria-invalid={!!errors.reservation_unit_id}
                    />
                    <FieldError>{errors.reservation_unit_id}</FieldError>
                </Field>
                <FieldSet>
                    <FieldLegend variant="label">Charges</FieldLegend>
                    <RadioGroup
                        value={data.charges}
                        onValueChange={(value) =>
                            form.setData('charges', value as Charges)
                        }
                    >
                        <FieldGroup className="gap-3">
                            {CHARGES.map((choice) => (
                                <Field
                                    key={choice.value}
                                    orientation="horizontal"
                                >
                                    <RadioGroupItem
                                        id={`routing-charges-${choice.value}`}
                                        value={choice.value}
                                    />
                                    <FieldContent>
                                        <FieldLabel
                                            htmlFor={`routing-charges-${choice.value}`}
                                        >
                                            {choice.label}
                                        </FieldLabel>
                                    </FieldContent>
                                </Field>
                            ))}
                        </FieldGroup>
                    </RadioGroup>
                </FieldSet>
                {data.charges === 'category' && (
                    <Field data-invalid={!!errors.category}>
                        <FieldLabel htmlFor="routing-category">
                            Category
                        </FieldLabel>
                        <EnumSelect
                            id="routing-category"
                            options={routing.categories}
                            value={data.category}
                            onValueChange={(value) =>
                                form.setData('category', value)
                            }
                            placeholder="Choose a category"
                            aria-invalid={!!errors.category}
                        />
                        <FieldError>{errors.category}</FieldError>
                    </Field>
                )}
                {data.charges === 'code' && (
                    <Field data-invalid={!!errors.transaction_code_id}>
                        <FieldLabel htmlFor="routing-code">
                            Charge code
                        </FieldLabel>
                        <EnumSelect
                            id="routing-code"
                            options={(chargeCodes ?? []).map((code) => ({
                                value: String(code.id),
                                label: `${code.code} ${code.name}`,
                            }))}
                            value={data.transaction_code_id}
                            onValueChange={(value) =>
                                form.setData('transaction_code_id', value)
                            }
                            placeholder="Choose a charge code"
                            disabled={chargeCodes === undefined}
                            aria-invalid={!!errors.transaction_code_id}
                        />
                        <FieldError>{errors.transaction_code_id}</FieldError>
                    </Field>
                )}
                <Field data-invalid={!!errors.target_folio_id}>
                    <FieldLabel htmlFor="routing-target">Goes to</FieldLabel>
                    <EnumSelect
                        id="routing-target"
                        options={routing.targets.map((target) => ({
                            value: String(target.id),
                            label: target.label,
                        }))}
                        value={data.target_folio_id}
                        onValueChange={(value) =>
                            form.setData('target_folio_id', value)
                        }
                        placeholder="Choose an open folio"
                        aria-invalid={!!errors.target_folio_id}
                    />
                    <FieldError>{errors.target_folio_id}</FieldError>
                </Field>
                <Alert>
                    <Info />
                    <AlertDescription>
                        Only later postings follow a new rule. Charges already
                        posted stay; move them with Transfer.
                    </AlertDescription>
                </Alert>
            </FieldGroup>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !ready}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Add rule
                </Button>
            </div>
        </form>
    );
}

/**
 * The reservation's routing rules and a form to add a Manual one. Billing mode rules are read only. The list is read
 * again each time the Sheet opens and after each change, so it never shows a folio or rule that changed meanwhile.
 */
export function RoutingRulesSheet({
    open,
    onOpenChange,
    reservationId,
    units,
    routing,
    chargeCodes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    routing?: App.Data.Folio.RoutingRulesData | null;
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
}) {
    useEffect(() => {
        if (open) {
            router.reload({ only: ['routingRules'] });
        }
    }, [open]);

    useFormOptions(open, chargeCodes !== undefined, 'chargeCodes');

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Routing rules</SheetTitle>
                    <SheetDescription>
                        Send charges to another folio of this reservation. The
                        most specific rule wins: a charge code, then a category,
                        then all charges.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-4 pb-4">
                    {!routing ? (
                        <div className="flex flex-col gap-2">
                            <Skeleton className="h-8 w-full" />
                            <Skeleton className="h-8 w-full" />
                            <Skeleton className="h-8 w-full" />
                        </div>
                    ) : (
                        <>
                            {routing.rules.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No rules yet. Every charge stays on the
                                    folio of its unit.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Unit</TableHead>
                                            <TableHead>Charges</TableHead>
                                            <TableHead>Goes to</TableHead>
                                            <TableHead>Source</TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {routing.rules.map((rule) => (
                                            <TableRow key={rule.id}>
                                                <TableCell>
                                                    {rule.unit}
                                                </TableCell>
                                                <TableCell>
                                                    {rule.charges}
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {rule.target}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {rule.sourceLabel}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {rule.canRemove && (
                                                        <RemoveRoutingRuleDialog
                                                            reservationId={
                                                                reservationId
                                                            }
                                                            rule={rule}
                                                        />
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                            <div className="flex flex-col gap-4 border-t pt-4">
                                <h3 className="font-heading text-sm font-medium">
                                    Add rule
                                </h3>
                                <AddRuleForm
                                    reservationId={reservationId}
                                    units={units}
                                    routing={routing}
                                    chargeCodes={chargeCodes}
                                />
                            </div>
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
