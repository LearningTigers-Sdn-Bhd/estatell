import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { ATTACHABLE_STATUSES } from '@/components/pms/reservations/guest-rows';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { move, store } from '@/routes/reservations/units/guests';

type Unit = App.Data.Reservations.ReservationUnitOptionData;

export type MovingGuest = {
    /** Null for the booker who does not stay: the guest is added to the unit, not moved. */
    unitId: number | null;
    guestId: number;
    name: string;
    unitLabel: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    guest: MovingGuest | null;
    units: Unit[];
};

function MoveGuestForm({
    open,
    onOpenChange,
    reservationId,
    guest,
    units,
}: Props) {
    const form = useForm({ target_unit_id: '' });
    const { data, errors } = form;
    const targetError =
        errors.target_unit_id ??
        (errors as Record<string, string | undefined>).guest_id;
    const staying = guest?.unitId === null;
    const targets = units.filter((unit) => unit.id !== guest?.unitId);

    const submit = () => {
        if (guest === null) {
            return;
        }

        const options = {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        };

        if (guest.unitId === null) {
            form.transform(() => ({ guest_id: guest.guestId }));
            form.post(
                store.url({
                    reservation: reservationId,
                    unit: Number(data.target_unit_id),
                }),
                options,
            );

            return;
        }

        form.post(
            move.url({
                reservation: reservationId,
                unit: guest.unitId,
                guestId: guest.guestId,
            }),
            options,
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={
                staying
                    ? `Put ${guest?.name ?? 'guest'} in a unit`
                    : `Move ${guest?.name ?? 'guest'} to another unit`
            }
            description={
                guest === null
                    ? undefined
                    : staying
                      ? 'The booker is not staying in any unit yet.'
                      : `From ${guest.unitLabel}`
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={staying ? 'Add to unit' : 'Move guest'}
            processing={form.processing}
            submitDisabled={data.target_unit_id === ''}
        >
            <Field data-invalid={!!targetError}>
                <FieldLabel htmlFor="move-guest-target">Move to</FieldLabel>
                <EnumSelect
                    id="move-guest-target"
                    options={targets.map((unit) => {
                        const open = ATTACHABLE_STATUSES.includes(
                            unit.status.value,
                        );

                        return {
                            value: String(unit.id),
                            label: unit.label,
                            disabled: !open,
                            description: open ? undefined : unit.status.label,
                        };
                    })}
                    value={data.target_unit_id}
                    onValueChange={(value) =>
                        form.setData('target_unit_id', value)
                    }
                    placeholder="Choose a unit"
                    aria-invalid={!!targetError}
                />
                {!staying && (
                    <FieldDescription>
                        A draft registration card is discarded. A signed card
                        stops the move.
                    </FieldDescription>
                )}
                <FieldError>{targetError}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export function MoveGuestDialog(props: Props) {
    return <MoveGuestForm key={useFreshOnOpen(props.open)} {...props} />;
}
