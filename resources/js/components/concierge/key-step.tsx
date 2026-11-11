import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const POLL_MS = 3000;

/** The key step: the room number and the door PIN. While the lock is still making the PIN the page asks again every few seconds. */
export function KeyStep({
    stay,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
}) {
    const pin = stay.pin;
    const pending = pin?.status === 'pending';

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['stay'] }),
            POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [pending]);

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold">You are checked in</h2>
                {stay.roomNumber && (
                    <p className="text-sm text-muted-foreground">
                        Your room is{' '}
                        <strong className="text-foreground">
                            {stay.roomNumber}
                        </strong>
                        .
                    </p>
                )}
            </div>

            {pending && (
                <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                    Making your door code. This takes a few seconds.
                </p>
            )}

            {pin?.status === 'ready' && (
                <div className="flex flex-col items-center gap-2 rounded-lg border p-6">
                    <p className="text-sm text-muted-foreground">Door code</p>
                    <p className="font-mono text-4xl font-semibold tracking-[0.3em]">
                        {pin.code}
                    </p>
                    {pin.validUntil && (
                        <p className="text-xs text-muted-foreground">
                            Valid until{' '}
                            {new Date(pin.validUntil).toLocaleString()}
                        </p>
                    )}
                    <p className="text-center text-xs text-muted-foreground">
                        Type the code on the lock, then press the lock button.
                        Keep this page open until you are in the room.
                    </p>
                </div>
            )}

            {pin?.status === 'failed' && (
                <p className="rounded-lg border border-destructive/50 p-4 text-sm text-pretty">
                    We could not make your door code. You are checked in. Ask
                    the front desk for your key and quote room{' '}
                    {stay.roomNumber ?? 'number'}.
                </p>
            )}
        </div>
    );
}
