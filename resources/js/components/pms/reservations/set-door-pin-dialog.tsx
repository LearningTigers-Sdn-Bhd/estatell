import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { replace } from '@/routes/reservations/units/door-pins';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unitId: number;
    passcode: App.Data.DoorLocks.DoorPasscodeData;
};

function SetDoorPinForm({
    open,
    onOpenChange,
    reservationId,
    unitId,
    passcode,
}: Props) {
    const form = useForm({ code: '' });
    const { data, errors } = form;
    const weak =
        /^(\d)\1+$/.test(data.code) || '0123456789'.includes(data.code);

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={`Set door PIN · ${passcode.lockName}`}
            description="Replaces the current PIN. The guest must be given this one."
            errors={errors}
            onSubmit={() =>
                form.post(
                    replace.url({
                        reservation: reservationId,
                        unit: unitId,
                        doorPasscode: passcode.id,
                    }),
                    {
                        preserveScroll: true,
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
            submitLabel="Set PIN"
            processing={form.processing}
            submitDisabled={!/^\d{4,9}$/.test(data.code)}
        >
            <Field data-invalid={!!errors.code}>
                <FieldLabel htmlFor="door-pin-code">New PIN</FieldLabel>
                <Input
                    id="door-pin-code"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={9}
                    className="font-mono tracking-wider"
                    value={data.code}
                    onChange={(event) =>
                        form.setData(
                            'code',
                            event.target.value.replace(/\D/g, ''),
                        )
                    }
                    aria-invalid={!!errors.code}
                />
                <FieldDescription>
                    4 to 9 digits.
                    {weak && data.code.length >= 4
                        ? ' This PIN is easy to guess.'
                        : ''}
                </FieldDescription>
                <FieldError>{errors.code}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Staff type a PIN for a checked-in stay. It replaces the stay's current PIN on the lock and needs a gateway.
 */
export function SetDoorPinDialog(props: Props) {
    return <SetDoorPinForm key={useFreshOnOpen(props.open)} {...props} />;
}
