import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { GuestPicker } from '@/components/pms/guest-picker';
import { FormOverlay } from '@/components/pms/form-overlay';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { store } from '@/routes/reservations/units/guests';

type Unit = App.Data.Reservations.ReservationUnitOptionData;
type Guest = App.Data.Guests.GuestOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    units: Unit[];
    initialUnitId: number | null;
};

function AttachGuestForm({
    open,
    onOpenChange,
    reservationId,
    units,
    initialUnitId,
}: Props) {
    const form = useForm({
        unit_id: String(initialUnitId ?? units[0]?.id ?? ''),
        guest_id: '',
    });
    const { data, errors } = form;
    const [guest, setGuest] = useState<Guest | null>(null);

    const submit = () => {
        form.transform(({ guest_id }) => ({ guest_id }));
        form.post(
            store.url({
                reservation: reservationId,
                unit: Number(data.unit_id),
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Attach guest"
            description="Choose the unit and the guest who stays in it."
            errors={errors}
            onSubmit={submit}
            submitLabel="Attach guest"
            processing={form.processing}
            submitDisabled={guest === null}
        >
            <Field>
                <FieldLabel htmlFor="attach-unit_id">Stays in</FieldLabel>
                <EnumSelect
                    id="attach-unit_id"
                    options={units.map((unit) => ({
                        value: String(unit.id),
                        label:
                            unit.status.value === 'checked_out'
                                ? `${unit.label} · ${unit.status.label}`
                                : unit.label,
                    }))}
                    value={data.unit_id}
                    onValueChange={(value) => form.setData('unit_id', value)}
                />
                {units.find((unit) => String(unit.id) === data.unit_id)?.status
                    .value === 'checked_out' && (
                    <FieldDescription>
                        This stay has ended. Adding a guest changes no charges.
                    </FieldDescription>
                )}
            </Field>
            <Field data-invalid={!!errors.guest_id}>
                <FieldLabel htmlFor="attach-guest_id">Guest</FieldLabel>
                <GuestPicker
                    id="attach-guest_id"
                    value={guest}
                    onChange={(next) => {
                        setGuest(next);
                        form.setData('guest_id', next ? String(next.id) : '');
                    }}
                    invalid={!!errors.guest_id}
                />
                <FieldDescription>
                    Existing guests only. Add a new guest from the Guests page
                    first.
                </FieldDescription>
                <FieldError>{errors.guest_id}</FieldError>
            </Field>
            {guest?.isBlacklisted && (
                <Alert>
                    <TriangleAlert />
                    <AlertDescription>
                        {guest.fullName} is on the blacklist. You can still
                        attach them.
                    </AlertDescription>
                </Alert>
            )}
        </FormOverlay>
    );
}

/**
 * Attaches an existing guest to a unit. Open units and checked-out units are offered.
 */
export function AttachGuestDialog(props: Props) {
    return <AttachGuestForm key={useFreshOnOpen(props.open)} {...props} />;
}
