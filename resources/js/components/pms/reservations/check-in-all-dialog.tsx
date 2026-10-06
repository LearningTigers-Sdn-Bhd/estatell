import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
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
import { checkInAll, checkInAllPreview } from '@/routes/reservations';

type Preview = App.Data.Reservations.CheckInAllPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: App.Data.Reservations.ReservationData;
};

function CheckInAllForm({ open, onOpenChange, reservation }: Props) {
    const key = { reservation: reservation.id };
    const { preview, reload } = useUnitPreview<Preview>(
        checkInAllPreview.url(key),
        open,
    );
    const [picked, setPicked] = useState<number[] | null>(null);
    const form = useForm({ without_card: false });
    const { data, errors } = form;
    const rowErrors = errors as Record<string, string | undefined>;
    const ready = preview?.rows.filter((row) => row.ready) ?? [];
    const selected = (picked ?? ready.map((row) => row.unitId)).filter((id) =>
        ready.some((row) => row.unitId === id),
    );
    const needsCard =
        preview?.rows.some(
            (row) => selected.includes(row.unitId) && row.cardsMissing > 0,
        ) ?? false;

    const toggle = (unitId: number, checked: boolean) =>
        setPicked(
            checked
                ? [...selected, unitId]
                : selected.filter((id) => id !== unitId),
        );

    const submit = () => {
        form.transform((values) => ({ ...values, unit_ids: selected }));
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
            open={open}
            onOpenChange={onOpenChange}
            title="Check in all"
            description={`${reservation.reference} · ${reservation.title}`}
            errors={errors}
            onSubmit={submit}
            submitLabel={
                selected.length === 1
                    ? 'Check in 1 unit'
                    : `Check in ${selected.length} units`
            }
            processing={form.processing}
            submitDisabled={selected.length === 0}
            loading={preview === null}
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
                    <div className="flex flex-col gap-2">
                        {preview.rows.map((row) => {
                            const error = rowErrors[`units.${row.unitId}`];

                            return (
                                <Field
                                    key={row.unitId}
                                    orientation="horizontal"
                                    data-invalid={!!error}
                                >
                                    <Checkbox
                                        id={`check-in-all-${row.unitId}`}
                                        checked={selected.includes(row.unitId)}
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
                                            {row.room ? ` · ${row.room}` : ''}
                                            {row.guest ? ` · ${row.guest}` : ''}
                                        </FieldLabel>
                                        {row.note !== null && (
                                            <FieldDescription>
                                                {row.note}
                                            </FieldDescription>
                                        )}
                                        {row.cardsMissing > 0 && row.ready && (
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
                                        form.setData('without_card', checked)
                                    }
                                />
                                <FieldContent>
                                    <span className="text-sm font-medium">
                                        Check in without a card
                                    </span>
                                    <FieldDescription>
                                        Cards stay unsigned. This is logged with
                                        your name.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    <p className="text-sm text-muted-foreground">
                        Check in all takes no payment. Collect the taxes and the
                        deposit for each unit afterwards.
                    </p>
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
