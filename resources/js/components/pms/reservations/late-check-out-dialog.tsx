import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { lateCheckOut, lateCheckOutPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitLateCheckOutPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    canWaiveFees: boolean;
    currency: App.Enums.Currency | null;
};

function hoursBetween(from: string, to: string): number {
    const [fromHour, fromMinute] = from.split(':').map(Number);
    const [toHour, toMinute] = to.split(':').map(Number);

    return (toHour * 60 + toMinute - (fromHour * 60 + fromMinute)) / 60;
}

function LateCheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    canWaiveFees,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const { preview, reload } = useUnitPreview<Preview>(
        lateCheckOutPreview.url(key),
        open,
    );
    const form = useForm({
        new_time: '',
        do_not_charge: false,
        reason: '',
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

    const hasFee = preview?.fee != null && Number(preview.fee) > 0;
    const showWaive = canWaiveFees && hasFee;
    const later =
        preview !== null &&
        data.new_time !== '' &&
        data.new_time > preview.departureTime;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            do_not_charge: showWaive && values.do_not_charge,
            expected_fee: preview?.fee ?? '',
        }));
        form.post(lateCheckOut.url(key), {
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
            title={`Late check-out · ${unit.roomType}`}
            description={unit.primaryGuest ?? 'No primary guest'}
            errors={errors}
            onSubmit={submit}
            submitLabel="Apply"
            processing={form.processing}
            submitDisabled={!later || preview?.error != null}
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    <div className="flex justify-between gap-4 text-sm">
                        <span className="text-muted-foreground">Departure</span>
                        <span>
                            <DateText date={unit.departure} /> ·{' '}
                            {preview.departureTime}
                            {preview.standardTime !== '' &&
                                ` (standard ${preview.standardTime})`}
                        </span>
                    </div>
                    <Field data-invalid={!!errors.new_time}>
                        <FieldLabel htmlFor="late-new_time">
                            New time
                        </FieldLabel>
                        <Input
                            id="late-new_time"
                            type="time"
                            value={data.new_time}
                            onChange={(event) =>
                                form.setData('new_time', event.target.value)
                            }
                            autoFocus
                            aria-invalid={!!errors.new_time}
                        />
                        {later && (
                            <FieldDescription>
                                +
                                {hoursBetween(
                                    preview.departureTime,
                                    data.new_time,
                                ).toFixed(1)}{' '}
                                hours. It must stay before midnight.
                            </FieldDescription>
                        )}
                        <FieldError>{errors.new_time}</FieldError>
                    </Field>
                    {preview.fee !== null && (
                        <div className="flex justify-between gap-4 text-sm">
                            <span className="text-muted-foreground">Fee</span>
                            <span className="font-medium">
                                {hasFee ? (
                                    <>
                                        <MoneyText
                                            amount={preview.fee}
                                            currency={currency}
                                        />{' '}
                                        <span className="font-normal text-muted-foreground">
                                            + tax
                                        </span>
                                    </>
                                ) : (
                                    'No charge'
                                )}
                            </span>
                        </div>
                    )}
                    <FieldError>{errors.expected_fee}</FieldError>
                    {showWaive && (
                        <FieldLabel htmlFor="late-do_not_charge">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="late-do_not_charge"
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
                        <FieldLabel htmlFor="late-reason">Reason</FieldLabel>
                        <Textarea
                            id="late-reason"
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
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Late check-out on the departure date: a later departure time and its policy fee. The fee is read only text from
 * the server, and Do not charge is for staff with `front-desk.waive-fees`.
 */
export function LateCheckOutDialog(props: Props) {
    return <LateCheckOutForm key={useFreshOnOpen(props.open)} {...props} />;
}
