import { useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { reinstate, reinstatePreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitReinstatePreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    currency: App.Enums.Currency | null;
};

function ReinstateUnitForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const { preview } = useUnitPreview<Preview>(
        reinstatePreview.url(key),
        open,
    );
    const form = useForm({ reason: '' });
    const { data, errors } = form;

    const submit = () => {
        form.post(reinstate.url(key), {
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
            title={`Reinstate · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Reinstate"
            processing={form.processing}
            submitDisabled={preview === null || preview.error !== null}
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    {preview.error !== null ? (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    ) : (
                        <dl className="flex flex-col gap-1 rounded-lg border p-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Was</dt>
                                <dd>
                                    <MoneyText
                                        amount={preview.previousTotal ?? '0'}
                                        currency={currency}
                                    />
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 font-medium">
                                <dt>Now</dt>
                                <dd>
                                    <MoneyText
                                        amount={preview.newTotal ?? '0'}
                                        currency={currency}
                                    />
                                </dd>
                            </div>
                        </dl>
                    )}
                    <Field data-invalid={!!errors.reason}>
                        <FieldLabel htmlFor="reinstate-reason">
                            Reason
                        </FieldLabel>
                        <Textarea
                            id="reinstate-reason"
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
                    <p className="text-sm text-muted-foreground">
                        The unit is priced again at today's rates. The rooms are
                        cleared, so assign them again. A fee already posted
                        stays on the folio. Reverse it there if needed.
                    </p>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Reinstate a cancelled or no-show unit with a required reason. Shows the unit total as booked and as it will be
 * priced again.
 */
export function ReinstateUnitDialog(props: Props) {
    return <ReinstateUnitForm key={useFreshOnOpen(props.open)} {...props} />;
}
