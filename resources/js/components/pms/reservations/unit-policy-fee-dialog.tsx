import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    cancel,
    cancelPreview,
    noShow,
    noShowPreview,
} from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type FeePreview = App.Data.Reservations.UnitFeePreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    kind: 'cancel' | 'no-show';
    canWaiveFees: boolean;
    currency: App.Enums.Currency | null;
};

const TEXT = {
    cancel: {
        title: 'Cancel unit',
        keep: 'Keep unit',
        policy: 'Cancellation policy',
        done: 'The room is released.',
    },
    'no-show': {
        title: 'Mark no-show',
        keep: 'Keep unit',
        policy: 'No-show policy',
        done: 'The room is released.',
    },
} as const;

function UnitPolicyFeeForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    kind,
    canWaiveFees,
    currency,
}: Props) {
    const text = TEXT[kind];
    const key = { reservation: reservationId, unit: unit.id };
    const { preview, reload } = useUnitPreview<FeePreview>(
        (kind === 'cancel' ? cancelPreview : noShowPreview).url(key),
        open,
    );
    const form = useForm({
        do_not_charge: false,
        reason: '',
        remove_add_ons: false,
        expected_fee: '',
    });
    const { data, errors } = form;

    useEffect(() => {
        if (errors.expected_fee) {
            reload();
        }
        // Ask again only when the server says the fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const isFree = preview?.fee === null || Number(preview?.fee) === 0;
    const showWaive = canWaiveFees && preview !== null && !isFree;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            do_not_charge: showWaive && values.do_not_charge,
            expected_fee: preview?.fee ?? '',
        }));
        form.post((kind === 'cancel' ? cancel : noShow).url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`${text.title} · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={text.title}
            submitVariant="destructive"
            processing={form.processing}
            submitDisabled={preview === null || preview.error !== null}
            loading={preview === null}
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {text.keep}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={
                            form.processing ||
                            preview === null ||
                            preview.error !== null
                        }
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        {text.title}
                    </Button>
                </>
            }
        >
            {preview !== null && (
                <>
                    {preview.error !== null ? (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    ) : (
                        <div className="flex flex-col gap-1 text-sm">
                            <span className="font-medium">{text.policy}</span>
                            {preview.freeUntilDays !== null && (
                                <span className="text-muted-foreground">
                                    Free until {preview.freeUntilDays}{' '}
                                    {preview.freeUntilDays === 1
                                        ? 'day'
                                        : 'days'}{' '}
                                    before arrival
                                </span>
                            )}
                            {preview.daysBeforeArrival !== null && (
                                <span className="text-muted-foreground">
                                    {preview.daysBeforeArrival < 0
                                        ? `Arrival was ${-preview.daysBeforeArrival} ${preview.daysBeforeArrival === -1 ? 'day' : 'days'} ago`
                                        : preview.daysBeforeArrival === 0
                                          ? 'Arrival is today'
                                          : `Today is ${preview.daysBeforeArrival} ${preview.daysBeforeArrival === 1 ? 'day' : 'days'} before arrival`}
                                </span>
                            )}
                            <div className="mt-1 flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Fee
                                </span>
                                <span className="font-medium">
                                    {isFree ? (
                                        'No charge'
                                    ) : (
                                        <>
                                            <MoneyText
                                                amount={preview.fee ?? '0'}
                                                currency={currency}
                                            />{' '}
                                            <span className="font-normal text-muted-foreground">
                                                + tax
                                            </span>
                                        </>
                                    )}
                                </span>
                            </div>
                            <FieldError>{errors.expected_fee}</FieldError>
                        </div>
                    )}
                    {showWaive && (
                        <FieldLabel htmlFor="unit-do_not_charge">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="unit-do_not_charge"
                                    checked={data.do_not_charge}
                                    onCheckedChange={(checked) =>
                                        form.setData('do_not_charge', checked)
                                    }
                                />
                                <FieldContent>
                                    <span className="text-sm font-medium">
                                        Do not charge
                                    </span>
                                    <FieldDescription>
                                        The fee is not posted to the folio. This
                                        is logged with your name.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="unit-reason">Reason</FieldLabel>
                        <Textarea
                            id="unit-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            placeholder={
                                showWaive && data.do_not_charge
                                    ? 'Required with Do not charge'
                                    : 'Optional'
                            }
                            aria-invalid={!!errors.reason}
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                    {preview.unpostedAddOns > 0 && (
                        <FieldLabel htmlFor="unit-remove_add_ons">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="unit-remove_add_ons"
                                    checked={data.remove_add_ons}
                                    onCheckedChange={(checked) =>
                                        form.setData('remove_add_ons', checked)
                                    }
                                />
                                <FieldContent>
                                    <span className="text-sm font-medium">
                                        Also remove add-ons
                                    </span>
                                    <FieldDescription>
                                        Removes {preview.unpostedAddOns} add-on
                                        {preview.unpostedAddOns === 1
                                            ? ''
                                            : 's'}{' '}
                                        not posted to a folio yet.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    <p className="text-sm text-muted-foreground">{text.done}</p>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Cancel unit and Mark no-show: the policy fee as read-only text from the server, Do not charge for staff with
 * `front-desk.waive-fees`, a reason and Also remove add-ons. The form sends choices and the fee it showed, never an
 * amount.
 */
export function UnitPolicyFeeDialog(props: Props) {
    return (
        <UnitPolicyFeeForm
            key={`${useFreshOnOpen(props.open)}-${props.kind}`}
            {...props}
        />
    );
}
