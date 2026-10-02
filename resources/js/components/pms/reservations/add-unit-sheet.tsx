import { useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { DiscardChangesDialog } from '@/components/pms/reservations/discard-changes-dialog';
import { FormError } from '@/components/pms/reservations/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/reservations/use-form-options';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/units';

type Options = App.Data.Reservations.AddUnitOptionsData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    options?: Options;
    currency: App.Enums.Currency | null;
};

const EMPTY = {
    room_type_id: '',
    rate_plan_id: '',
    arrival_date: '',
    departure_date: '',
    adults: '2',
    child_ages: '',
    override_total: '',
    override_reason: '',
    restriction_override_reason: '',
};

/** "4, 9" becomes [4, 9]. A bad entry stays a string, so the server refuses it and the field shows the error. */
function parseAges(text: string): (number | string)[] {
    return text
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .map((part) => (/^\d+$/.test(part) ? Number(part) : part));
}

/**
 * Adds one booking line to the reservation. The room is not picked here: it is assigned when the guest arrives.
 */
export function AddUnitSheet({
    open,
    onOpenChange,
    reservationId,
    options,
    currency,
}: Props) {
    useFormOptions(open, options !== undefined, 'addUnitOptions');

    const form = useForm(EMPTY);
    const { data, errors } = form;
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [restrictionAsked, setRestrictionAsked] = useState(false);

    const close = () => {
        form.reset();
        form.clearErrors();
        setRestrictionAsked(false);
        setOverrideOpen(false);
        setConfirmingDiscard(false);
        onOpenChange(false);
    };

    const requestClose = (next: boolean) => {
        if (next) {
            onOpenChange(true);
        } else if (form.isDirty) {
            setConfirmingDiscard(true);
        } else {
            close();
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            child_ages: parseAges(values.child_ages),
        }));
        form.post(store.url({ reservation: reservationId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: close,
            onError: (formErrors) => {
                if (formErrors.restriction_override_reason) {
                    setRestrictionAsked(true);
                }
            },
        });
    };

    const roomTypes = options?.roomTypes ?? [];
    const ratePlans = (options?.ratePlans ?? []).filter(
        (plan) =>
            data.room_type_id === '' ||
            plan.roomTypeIds.includes(Number(data.room_type_id)),
    );
    const roomType = roomTypes.find(
        (type) => String(type.id) === data.room_type_id,
    );
    const overrideInvalid = !!errors.override_total || !!errors.override_reason;
    const childAgesError = Object.entries(errors).find(([key]) =>
        key.startsWith('child_ages'),
    )?.[1];

    return (
        <>
            <Sheet open={open} onOpenChange={requestClose}>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Add unit</SheetTitle>
                        <SheetDescription>
                            One room, or one bed in a dorm. Add more units one
                            at a time.
                        </SheetDescription>
                    </SheetHeader>
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <FieldGroup className="flex-1 overflow-y-auto px-4">
                            <FormError errors={errors} />
                            <Field data-invalid={!!errors.room_type_id}>
                                <FieldLabel htmlFor="unit-room_type_id">
                                    Room type
                                </FieldLabel>
                                <EnumSelect
                                    id="unit-room_type_id"
                                    options={roomTypes.map((type) => ({
                                        value: String(type.id),
                                        label: type.name,
                                    }))}
                                    value={data.room_type_id}
                                    onValueChange={(value) => {
                                        form.setData((current) => ({
                                            ...current,
                                            room_type_id: value,
                                            rate_plan_id: '',
                                        }));
                                    }}
                                    aria-invalid={!!errors.room_type_id}
                                />
                                <FieldError>{errors.room_type_id}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.rate_plan_id}>
                                <FieldLabel htmlFor="unit-rate_plan_id">
                                    Rate plan
                                </FieldLabel>
                                <EnumSelect
                                    id="unit-rate_plan_id"
                                    options={ratePlans.map((plan) => ({
                                        value: String(plan.id),
                                        label: plan.name,
                                    }))}
                                    value={data.rate_plan_id}
                                    onValueChange={(value) =>
                                        form.setData('rate_plan_id', value)
                                    }
                                    disabled={data.room_type_id === ''}
                                    aria-invalid={!!errors.rate_plan_id}
                                />
                                <FieldError>{errors.rate_plan_id}</FieldError>
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field data-invalid={!!errors.arrival_date}>
                                    <FieldLabel htmlFor="unit-arrival_date">
                                        Arrival
                                    </FieldLabel>
                                    <DatePicker
                                        id="unit-arrival_date"
                                        value={data.arrival_date}
                                        onChange={(value) =>
                                            form.setData('arrival_date', value)
                                        }
                                        aria-invalid={!!errors.arrival_date}
                                    />
                                    <FieldError>
                                        {errors.arrival_date}
                                    </FieldError>
                                </Field>
                                <Field data-invalid={!!errors.departure_date}>
                                    <FieldLabel htmlFor="unit-departure_date">
                                        Departure
                                    </FieldLabel>
                                    <DatePicker
                                        id="unit-departure_date"
                                        value={data.departure_date}
                                        min={data.arrival_date || undefined}
                                        onChange={(value) =>
                                            form.setData(
                                                'departure_date',
                                                value,
                                            )
                                        }
                                        aria-invalid={!!errors.departure_date}
                                    />
                                    <FieldError>
                                        {errors.departure_date}
                                    </FieldError>
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field data-invalid={!!errors.adults}>
                                    <FieldLabel htmlFor="unit-adults">
                                        Adults
                                    </FieldLabel>
                                    <Input
                                        id="unit-adults"
                                        type="number"
                                        min={1}
                                        max={99}
                                        value={data.adults}
                                        onChange={(event) =>
                                            form.setData(
                                                'adults',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!errors.adults}
                                    />
                                    {roomType && (
                                        <FieldDescription>
                                            Up to {roomType.maxAdults} for this
                                            room type.
                                        </FieldDescription>
                                    )}
                                    <FieldError>{errors.adults}</FieldError>
                                </Field>
                                <Field data-invalid={!!childAgesError}>
                                    <FieldLabel htmlFor="unit-child_ages">
                                        Child ages
                                    </FieldLabel>
                                    <Input
                                        id="unit-child_ages"
                                        value={data.child_ages}
                                        onChange={(event) =>
                                            form.setData(
                                                'child_ages',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="4, 9"
                                        autoComplete="off"
                                        aria-invalid={!!childAgesError}
                                    />
                                    <FieldDescription>
                                        Separate ages with commas.
                                    </FieldDescription>
                                    <FieldError>{childAgesError}</FieldError>
                                </Field>
                            </div>
                            <Collapsible
                                open={overrideOpen || overrideInvalid}
                                onOpenChange={setOverrideOpen}
                                className="flex flex-col gap-4"
                            >
                                <CollapsibleTrigger
                                    render={
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="justify-between"
                                        />
                                    }
                                >
                                    Price override
                                    <ChevronDown data-icon="inline-end" />
                                </CollapsibleTrigger>
                                <CollapsibleContent className="flex flex-col gap-4">
                                    <Field
                                        data-invalid={!!errors.override_total}
                                    >
                                        <FieldLabel htmlFor="unit-override_total">
                                            Stay total
                                        </FieldLabel>
                                        <MoneyInput
                                            id="unit-override_total"
                                            value={data.override_total}
                                            onChange={(value) =>
                                                form.setData(
                                                    'override_total',
                                                    value,
                                                )
                                            }
                                            currency={currency}
                                            invalid={!!errors.override_total}
                                        />
                                        <FieldDescription>
                                            Replaces the quoted price for this
                                            unit. Leave empty to use the rate.
                                        </FieldDescription>
                                        <FieldError>
                                            {errors.override_total}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={!!errors.override_reason}
                                    >
                                        <FieldLabel htmlFor="unit-override_reason">
                                            Reason
                                        </FieldLabel>
                                        <Input
                                            id="unit-override_reason"
                                            value={data.override_reason}
                                            onChange={(event) =>
                                                form.setData(
                                                    'override_reason',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={500}
                                            placeholder="Corporate rate"
                                            aria-invalid={
                                                !!errors.override_reason
                                            }
                                        />
                                        <FieldError>
                                            {errors.override_reason}
                                        </FieldError>
                                    </Field>
                                </CollapsibleContent>
                            </Collapsible>
                            {restrictionAsked && (
                                <Field
                                    data-invalid={
                                        !!errors.restriction_override_reason
                                    }
                                >
                                    <FieldLabel htmlFor="unit-restriction_override_reason">
                                        Restriction override reason
                                    </FieldLabel>
                                    <Input
                                        id="unit-restriction_override_reason"
                                        value={data.restriction_override_reason}
                                        onChange={(event) =>
                                            form.setData(
                                                'restriction_override_reason',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={500}
                                        aria-invalid={
                                            !!errors.restriction_override_reason
                                        }
                                    />
                                    <FieldError>
                                        {errors.restriction_override_reason}
                                    </FieldError>
                                </Field>
                            )}
                        </FieldGroup>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => requestClose(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing || !options}
                                focusableWhenDisabled
                            >
                                {form.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Add unit
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <DiscardChangesDialog
                open={confirmingDiscard}
                onOpenChange={setConfirmingDiscard}
                title="Discard this unit?"
                onDiscard={close}
            />
        </>
    );
}
