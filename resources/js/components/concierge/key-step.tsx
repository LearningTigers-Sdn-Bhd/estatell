import { Link, router } from '@inertiajs/react';
import { ArrowRight, CircleCheck, KeyRound } from 'lucide-react';
import { useEffect } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { stayContact } from '@/routes/concierge';

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
        <section
            aria-labelledby="checked-in-title"
            className="grid gap-8 border-b border-border/40 pb-8 md:grid-cols-2 md:items-center md:gap-12"
        >
            <div className="flex items-center justify-between gap-6">
                <div className="flex min-w-0 flex-col items-start gap-5">
                    <h2
                        id="checked-in-title"
                        className="flex items-center gap-2 text-sm font-medium text-primary"
                    >
                        <CircleCheck className="size-5" aria-hidden />
                        You’re checked in
                    </h2>
                    {stay.roomNumber && (
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-sm text-muted-foreground">
                                Your room
                            </p>
                            <p className="text-6xl font-semibold tracking-tight break-words tabular-nums sm:text-7xl">
                                {stay.roomNumber}
                            </p>
                        </div>
                    )}
                </div>
                {pin?.status === 'failed' && (
                    <img
                        src="/images/concierge-host.png"
                        alt=""
                        width={1024}
                        height={1024}
                        className="size-40 shrink-0 object-contain sm:size-48"
                    />
                )}
            </div>

            {pending && (
                <div
                    role="status"
                    className="flex flex-col items-start gap-4 rounded-2xl bg-muted/50 p-6 sm:p-8"
                >
                    <Spinner className="size-6 text-primary" />
                    <div className="flex flex-col gap-2">
                        <h3 className="text-lg font-semibold">
                            Preparing your door code
                        </h3>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            This may take a few seconds. Your code will appear
                            here when it’s ready.
                        </p>
                    </div>
                </div>
            )}

            {pin?.status === 'ready' && (
                <div className="flex min-w-0 flex-col gap-4 rounded-2xl bg-muted/50 p-6 sm:p-8">
                    <h3 className="flex items-center gap-2 text-sm font-medium">
                        <KeyRound className="size-4 text-primary" aria-hidden />{' '}
                        Your door code
                    </h3>
                    <p className="font-mono text-4xl font-semibold tracking-widest break-all tabular-nums">
                        {pin.code}
                    </p>
                    {pin.validUntil && (
                        <p className="text-xs text-muted-foreground">
                            Valid until{' '}
                            {new Date(pin.validUntil).toLocaleString()}
                        </p>
                    )}
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Type the code on the lock, then press the lock button.
                        Keep this page open until you are in the room.
                    </p>
                </div>
            )}

            {pin?.status === 'failed' && (
                <div
                    role="status"
                    className="flex flex-col items-start gap-5 rounded-2xl bg-muted/50 p-6 sm:p-8"
                >
                    <div className="flex size-11 items-center justify-center rounded-full bg-background text-primary">
                        <KeyRound className="size-5" aria-hidden />
                    </div>
                    <div className="flex flex-col gap-2">
                        <h3 className="text-xl font-semibold text-balance">
                            Collect your key at the front desk
                        </h3>
                        <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                            Your check-in is complete, but your door code is
                            unavailable. Please visit the front desk
                            {stay.roomNumber ? (
                                <>
                                    {' '}
                                    and let the team know you’re in room{' '}
                                    <strong className="font-medium text-foreground">
                                        {stay.roomNumber}
                                    </strong>
                                </>
                            ) : null}{' '}
                            to collect your key.
                        </p>
                    </div>
                    <Link
                        href={stayContact.url({ token: stay.token })}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'lg',
                            className: 'min-h-11',
                        })}
                    >
                        Contact front desk <ArrowRight aria-hidden />
                    </Link>
                </div>
            )}
        </section>
    );
}
