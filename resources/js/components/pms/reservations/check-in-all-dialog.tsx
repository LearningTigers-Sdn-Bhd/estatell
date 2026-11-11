import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { formatMoney } from '@/components/pms/money-text';
import {
    EndedStayColumns,
    EndedStayQuestion,
} from '@/components/pms/reservations/ended-stay-question';
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
import { checkInAll, checkInAllPreview } from '@/routes/reservations';

type Preview = App.Data.Reservations.CheckInAllPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: App.Data.Reservations.ReservationData;
    currency: App.Data.Property.CurrencyData | null;
};

function CheckInAllForm({ open, onOpenChange, reservation, currency }: Props) {
    const key = { reservation: reservation.id };
    const { preview, reload } = useUnitPreview<Preview>(
        checkInAllPreview.url(key),
        open,
    );
    const [picked, setPicked] = useState<number[] | null>(null);
    const form = useForm({
        without_card: false,
        ended_stay_choice: 'arrived_last_night',
        arrived_at: '',
    });
    const { data, errors } = form;
    const rowErrors = errors as Record<string, string | undefined>;
    const ready = preview?.rows.filter((row) => row.ready) ?? [];
    const selected = (picked ?? ready.map((row) => row.unitId)).filter((id) =>
        ready.some((row) => row.unitId === id),
    );
    const nothingToCheckIn = preview !== null && preview.rows.length === 0;
    const noneReady =
        preview !== null && preview.rows.length > 0 && ready.length === 0;
    const needsCard =
        preview?.rows.some(
            (row) => selected.includes(row.unitId) && row.cardsMissing > 0,
        ) ?? false;

    const endedStay = preview?.endedStay ?? null;
    const endedRows = ready.filter(
        (row) => row.endedStay && selected.includes(row.unitId),
    );
    const isMixed = ready.some((row) => !row.endedStay);
    const arrivingToday = data.ended_stay_choice === 'arriving_today';
    // Display only: the server prices each move again.
    const moveTotal = endedRows.reduce(
        (sum, row) => sum + Number(row.moveTotal ?? 0),
        0,
    );

    const toggle = (unitId: number, checked: boolean) =>
        setPicked(
            checked
                ? [...selected, unitId]
                : selected.filter((id) => id !== unitId),
        );

    const submit = () => {
        form.transform((values) => ({
            ...values,
            unit_ids: selected,
            ended_stay_choice:
                endedRows.length > 0 ? values.ended_stay_choice : '',
            arrived_at:
                endedRows.length > 0 && !arrivingToday ? values.arrived_at : '',
        }));
        form.post(checkInAll.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
            onError: () => {
                // Units that were checked in leave the list, so the ones that failed stay.
                setPicked(selected);
                reload();
            },
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            size={endedStay !== null ? 'lg' : undefined}
            open={open}
            onOpenChange={onOpenChange}
            title="Check in all"
            description={`${reservation.reference} · ${reservation.title}`}
            errors={errors}
            onSubmit={submit}
            submitLabel={
                selected.length === 0
                    ? 'Check in'
                    : selected.length === 1
                      ? 'Check in 1 unit'
                      : `Check in ${selected.length} units`
            }
            processing={form.processing}
            submitDisabled={
                selected.length === 0 ||
                (endedRows.length > 0 &&
                    !arrivingToday &&
                    data.arrived_at === '')
            }
            loading={preview === null}
            footer={
                nothingToCheckIn ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                ) : undefined
            }
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    {preview.rows.length === 0 && preview.error === null && (
                        <p className="text-sm text-muted-foreground">
                            No unit is waiting to check in.
                        </p>
                    )}
                    {noneReady && (
                        <p className="text-sm font-medium">
                            No guest in this booking can check in today. The
                            reason is under each name.
                        </p>
                    )}
                    <EndedStayColumns
                        question={
                            endedStay !== null ? (
                                <EndedStayQuestion
                                    id="check-in-all"
                                    plural
                                    intro={
                                        endedStay.oneNight
                                            ? `This booking was for last night (${endedStay.lastNight}) and was not checked in.`
                                            : `This booking's last night was ${endedStay.lastNight} and it was not checked in.`
                                    }
                                    legend="Do you want to check the guests in for the previous business day?"
                                    keepLabel="Yes, they arrived after night audit was done"
                                    moveLabel="No, they arrive today"
                                    stayedDescription={`Keeps ${endedStay.stayDates}. The guests came for the booked ${endedStay.oneNight ? 'night' : 'nights'}. This includes arriving after midnight, such as 2:00 a.m. today. ${endedStay.oneNight ? 'That night is' : 'Those nights are'} charged for each room.`}
                                    arrivingDescription={
                                        endedStay.arrivingTodayBlocked ??
                                        `Changes the ticked rooms to ${endedStay.today} → ${endedStay.tomorrow}. Only if the guests have not used the rooms yet and will sleep here tonight. They leave tomorrow. New total ${formatMoney(String(moveTotal), currency)}.`
                                    }
                                    arrivingDisabled={
                                        endedStay.arrivingTodayBlocked !== null
                                    }
                                    choice={data.ended_stay_choice}
                                    onChoiceChange={(value) =>
                                        form.setData('ended_stay_choice', value)
                                    }
                                    arrivedAt={data.arrived_at}
                                    onArrivedAtChange={(value) =>
                                        form.setData('arrived_at', value)
                                    }
                                    errors={errors}
                                />
                            ) : null
                        }
                    >
                        <div className="flex flex-col gap-2">
                            {preview.rows.map((row) => {
                                const error = rowErrors[`units.${row.unitId}`];

                                return (
                                    <Field
                                        key={row.unitId}
                                        orientation="horizontal"
                                        data-invalid={!!error}
                                        data-disabled={!row.ready}
                                    >
                                        <Checkbox
                                            id={`check-in-all-${row.unitId}`}
                                            checked={selected.includes(
                                                row.unitId,
                                            )}
                                            disabled={!row.ready}
                                            onCheckedChange={(checked) =>
                                                toggle(row.unitId, checked)
                                            }
                                        />
                                        <FieldContent>
                                            <FieldLabel
                                                htmlFor={`check-in-all-${row.unitId}`}
                                            >
                                                {row.label}
                                                {row.room
                                                    ? ` · ${row.room}`
                                                    : ''}
                                                {row.guest
                                                    ? ` · ${row.guest}`
                                                    : ''}
                                            </FieldLabel>
                                            {isMixed && row.endedStay && (
                                                <FieldDescription>
                                                    Late room: uses the answer
                                                    above.
                                                </FieldDescription>
                                            )}
                                            {row.note !== null && (
                                                <FieldDescription>
                                                    {row.note}
                                                </FieldDescription>
                                            )}
                                            {row.cardsMissing > 0 &&
                                                row.ready && (
                                                    <FieldDescription>
                                                        {row.cardsMissing === 1
                                                            ? '1 registration card not signed'
                                                            : `${row.cardsMissing} registration cards not signed`}
                                                    </FieldDescription>
                                                )}
                                            <FieldError>{error}</FieldError>
                                        </FieldContent>
                                    </Field>
                                );
                            })}
                        </div>
                        {needsCard && preview.canCheckInWithoutCard && (
                            <FieldLabel htmlFor="check-in-all-without_card">
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="check-in-all-without_card"
                                        checked={data.without_card}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'without_card',
                                                checked,
                                            )
                                        }
                                    />
                                    <FieldContent>
                                        <span className="text-sm font-medium">
                                            Check in without a card
                                        </span>
                                        <FieldDescription>
                                            Cards stay unsigned. This is logged
                                            with your name.
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            </FieldLabel>
                        )}
                        {!noneReady && !nothingToCheckIn && (
                            <p className="text-sm text-muted-foreground">
                                Check in all takes no payment. Collect the taxes
                                and the deposit for each unit afterwards.
                            </p>
                        )}
                    </EndedStayColumns>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Checks in several units of a reservation in one step. A unit that cannot be checked in shows its reason on its own
 * row and does not stop the others. It takes no payment.
 */
export function CheckInAllDialog(props: Props) {
    return <CheckInAllForm key={useFreshOnOpen(props.open)} {...props} />;
}
