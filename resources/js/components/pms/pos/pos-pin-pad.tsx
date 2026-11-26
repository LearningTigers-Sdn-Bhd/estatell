import { router } from '@inertiajs/react';
import { Delete } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { switchMethod } from '@/routes/pos/pin';
import type { PosStaff } from './pos-types';

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
const PIN_SLOTS = [0, 1, 2, 3, 4, 5];

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * Pick-Name + PIN: supports both touch keypad and physical keyboard / USB numpad (`0-9`, `Backspace`, `Enter`, `Escape`).
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

    const submit = useCallback(
        (value: string) => {
            if (person === null || value.length < 4 || processing) {
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
        },
        [person, processing, onDone],
    );

    useEffect(() => {
        if (person === null) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (processing) {
                return;
            }

            if (/^[0-9]$/.test(event.key)) {
                event.preventDefault();
                setPin((current) => (current + event.key).slice(0, 6));
            } else if (event.key === 'Backspace') {
                event.preventDefault();
                setPin((current) => current.slice(0, -1));
            } else if (event.key === 'Enter' && pin.length >= 4) {
                event.preventDefault();
                submit(pin);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                setPerson(null);
                setPin('');
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [person, pin, processing, submit]);

    if (person === null) {
        return (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {staff.map((member) => (
                    <button
                        key={member.id}
                        type="button"
                        onClick={() => setPerson(member)}
                        className="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border bg-card p-3 text-center transition-colors hover:border-foreground/25 hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:translate-y-px"
                    >
                        <span
                            aria-hidden="true"
                            className="flex size-10 items-center justify-center rounded-full bg-secondary font-heading text-sm font-semibold text-secondary-foreground"
                        >
                            {initials(member.name)}
                        </span>
                        <span className="line-clamp-2 text-sm font-medium text-foreground">
                            {member.name}
                        </span>
                    </button>
                ))}
            </div>
        );
    }

    return (
        <div className="mx-auto flex w-full max-w-xs flex-col gap-4">
            <div className="flex items-center justify-between gap-2 rounded-xl border bg-muted/30 px-3 py-2">
                <div className="flex items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        className="flex size-9 items-center justify-center rounded-full bg-primary/15 font-heading text-xs font-semibold text-primary"
                    >
                        {initials(person.name)}
                    </span>
                    <p className="font-medium text-foreground">{person.name}</p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="min-h-11"
                    onClick={() => {
                        setPerson(null);
                        setPin('');
                    }}
                >
                    Change
                </Button>
            </div>

            <div
                className="flex items-center justify-center gap-2.5 py-2"
                role="status"
                aria-label={`${pin.length} of 6 digits entered`}
            >
                {PIN_SLOTS.map((slot) => {
                    const filled = slot < pin.length;
                    const required = slot < 4;

                    return (
                        <span
                            key={slot}
                            aria-hidden="true"
                            className={cn(
                                'flex size-10 items-center justify-center rounded-lg border transition-colors',
                                filled
                                    ? 'border-primary bg-primary/10'
                                    : required
                                      ? 'border-border bg-card'
                                      : 'border-dashed border-border/70 bg-muted/30',
                            )}
                        >
                            {filled && (
                                <span className="size-3 rounded-full bg-primary" />
                            )}
                        </span>
                    );
                })}
            </div>

            <div className="grid grid-cols-3 gap-2">
                {KEYS.map((key) => (
                    <Button
                        key={key}
                        type="button"
                        variant="outline"
                        className="h-14 font-heading text-xl font-semibold tabular-nums"
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
                    disabled={processing || pin.length === 0}
                    onClick={() => setPin((current) => current.slice(0, -1))}
                >
                    <Delete aria-hidden="true" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="h-14 font-heading text-xl font-semibold tabular-nums"
                    disabled={processing}
                    onClick={() =>
                        setPin((current) => (current + '0').slice(0, 6))
                    }
                >
                    0
                </Button>
                <Button
                    type="button"
                    className="h-14 font-semibold"
                    disabled={processing || pin.length < 4}
                    focusableWhenDisabled
                    onClick={() => submit(pin)}
                >
                    {processing && <Spinner data-icon="inline-start" />}
                    Sign in
                </Button>
            </div>
            <p className="text-center text-xs text-muted-foreground">
                Enter 4 to 6 digits, or use your numpad.
            </p>
        </div>
    );
}
