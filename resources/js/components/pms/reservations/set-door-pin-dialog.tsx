import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { replace } from '@/routes/reservations/units/door-pins';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unitId: number;
    passcode: App.Data.DoorLocks.DoorPasscodeData;
};

const STEPS = [
    'Sending the new PIN to the gateway…',
    'Waiting for the lock to confirm…',
    'Taking the old PIN off the lock…',
    'Almost there…',
];

/** The gateway talks to the lock over Bluetooth and can take up to 30 seconds, so show what is going on instead of a frozen button. */
function Working() {
    const [step, setStep] = useState(0);

    useEffect(() => {
        const timer = setInterval(
            () => setStep((current) => Math.min(current + 1, STEPS.length - 1)),
            3500,
        );

        return () => clearInterval(timer);
    }, []);

    return (
        <div
            role="status"
            className="flex flex-col gap-2 rounded-lg bg-muted/50 p-3 text-sm"
        >
            <span className="flex items-center gap-2 font-medium">
                <Spinner /> {STEPS[step]}
            </span>
            <Progress value={((step + 1) / STEPS.length) * 100} />
            <span className="text-xs text-muted-foreground">
                This can take up to 30 seconds. Keep this window open.
            </span>
        </div>
    );
}

/** TTLock refuses these (`-2032`): one digit repeated, or digits going up or down by one. Mirrors `PinStrength` in PHP. */
function isSimple(code: string): boolean {
    if (code.length < 2) {
        return false;
    }

    const steps = new Set(
        [...code].slice(1).map((digit, i) => Number(digit) - Number(code[i])),
    );

    return steps.size === 1 && [0, 1, -1].includes([...steps][0]);
}

function SetDoorPinForm({
    open,
    onOpenChange,
    reservationId,
    unitId,
    passcode,
}: Props) {
    const form = useForm({ code: '' });
    const { data, errors } = form;
    const simple = data.code.length >= 4 && isSimple(data.code);

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
            submitDisabled={!/^\d{4,9}$/.test(data.code) || simple}
        >
            <Field data-invalid={!!errors.code}>
                <FieldLabel htmlFor="door-pin-code">New PIN</FieldLabel>
                <Input
                    id="door-pin-code"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={9}
                    disabled={form.processing}
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
                <FieldDescription>4 to 9 digits.</FieldDescription>
                <FieldError>
                    {simple
                        ? 'TTLock refuses PINs like 1111 or 1234. Pick another.'
                        : errors.code}
                </FieldError>
            </Field>
            {form.processing && <Working />}
        </FormOverlay>
    );
}

/**
 * Staff type a PIN for a checked-in stay. It replaces the stay's current PIN on the lock and needs a gateway.
 */
export function SetDoorPinDialog(props: Props) {
    return <SetDoorPinForm key={useFreshOnOpen(props.open)} {...props} />;
}
