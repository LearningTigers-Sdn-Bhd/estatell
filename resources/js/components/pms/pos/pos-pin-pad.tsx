import { router } from '@inertiajs/react';
import { Delete } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { switchMethod } from '@/routes/pos/pin';
import type { PosStaff } from './pos-types';

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

/**
 * Pick-Name + PIN: the cashier taps their name, then keys their PIN. The server checks it and switches the active actor.
 */
export function PosPinPad({
    staff,
    onDone,
}: {
    staff: PosStaff[];
    onDone?: () => void;
}) {
    const [person, setPerson] = useState<PosStaff | null>(null);
    const [pin, setPin] = useState('');
    const [processing, setProcessing] = useState(false);

    function submit(value: string) {
        if (person === null || value.length < 4) {
            return;
        }

        router.post(
            switchMethod.url(),
            { user_id: person.id, pin: value },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setPin('');
                },
                onSuccess: () => onDone?.(),
            },
        );
    }

    if (person === null) {
        return (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {staff.map((member) => (
                    <Button
                        key={member.id}
                        type="button"
                        variant="outline"
                        className="h-16 text-base"
                        onClick={() => setPerson(member)}
                    >
                        {member.name}
                    </Button>
                ))}
            </div>
        );
    }

    return (
        <div className="mx-auto flex w-full max-w-xs flex-col gap-4">
            <div className="flex items-center justify-between gap-2">
                <p className="font-medium">{person.name}</p>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setPerson(null);
                        setPin('');
                    }}
                >
                    Not you?
                </Button>
            </div>
            <p
                className="text-center text-3xl tracking-widest tabular-nums"
                aria-label={`${pin.length} digits entered`}
            >
                {pin.length === 0 ? '·' : '•'.repeat(pin.length)}
            </p>
            <div className="grid grid-cols-3 gap-2">
                {KEYS.map((key) => (
                    <Button
                        key={key}
                        type="button"
                        variant="outline"
                        className="h-14 text-xl"
                        disabled={processing}
                        onClick={() =>
                            setPin((current) => (current + key).slice(0, 6))
                        }
                    >
                        {key}
                    </Button>
                ))}
                <Button
                    type="button"
                    variant="ghost"
                    className="h-14"
                    aria-label="Delete last digit"
                    disabled={processing}
                    onClick={() => setPin((current) => current.slice(0, -1))}
                >
                    <Delete />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="h-14 text-xl"
                    disabled={processing}
                    onClick={() =>
                        setPin((current) => (current + '0').slice(0, 6))
                    }
                >
                    0
                </Button>
                <Button
                    type="button"
                    className="h-14"
                    disabled={processing || pin.length < 4}
                    focusableWhenDisabled
                    onClick={() => submit(pin)}
                >
                    Sign in
                </Button>
            </div>
        </div>
    );
}
