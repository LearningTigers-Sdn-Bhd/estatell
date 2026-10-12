import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { undoCheckIn, undoCheckInPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitUndoPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    currency: App.Enums.Currency | null;
};

function UndoCheckInForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const { preview } = useUnitPreview<Preview>(
        undoCheckInPreview.url(key),
        open,
    );
    const form = useForm({ reason: '' });
    const { data, errors } = form;
    const stays = preview !== null && preview.error === null;

    const submit = () => {
        form.post(undoCheckIn.url(key), {
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
            title={`Undo check-in · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} />
                    {unit.stayLabel === null ? (
                        <>
                            {' '}
                            → <DateText date={unit.departure} />
                        </>
                    ) : (
                        <> · {unit.stayLabel}</>
                    )}
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Undo check-in"
            submitVariant="destructive"
            processing={form.processing}
            submitDisabled={!stays}
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    {preview.error !== null ? (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    ) : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                The unit goes back to Confirmed and keeps its
                                room. Nothing is posted or reversed.
                            </p>
                            {(preview.charges.length > 0 ||
                                Number(preview.paid) !== 0) && (
                                <dl className="flex flex-col gap-1 rounded-lg border p-3 text-sm">
                                    <dt className="font-medium">
                                        Stays on the folio
                                    </dt>
                                    {preview.charges.map((line, index) => (
                                        <div
                                            key={index}
                                            className="flex justify-between gap-4"
                                        >
                                            <dd className="text-muted-foreground">
                                                {line.description}
                                            </dd>
                                            <dd>
                                                <MoneyText
                                                    amount={line.amount}
                                                    currency={currency}
                                                />
                                            </dd>
                                        </div>
                                    ))}
                                    {Number(preview.paid) !== 0 && (
                                        <div className="flex justify-between gap-4">
                                            <dd className="text-muted-foreground">
                                                Paid
                                            </dd>
                                            <dd>
                                                <MoneyText
                                                    amount={preview.paid}
                                                    currency={currency}
                                                />
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                        </>
                    )}
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="undo-check-in-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="undo-check-in-reason"
                            value={data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={500}
                            rows={2}
                            autoFocus
                            aria-invalid={!!errors.reason}
                        />
                        <FieldError>{errors.reason}</FieldError>
                    </Field>
                    {stays && (
                        <p className="text-sm text-muted-foreground">
                            Reverse a charge from the folio if it no longer
                            applies. Cancel the unit afterwards if the guest is
                            not staying.
                        </p>
                    )}
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Undo a check-in made during the open business date, with a required reason. Lists the charges and payments that
 * stay on the folio. Check-in cannot be undone once the night audit has run or a night is posted.
 */
export function UndoCheckInDialog(props: Props) {
    return <UndoCheckInForm key={useFreshOnOpen(props.open)} {...props} />;
}
