import { router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePollWhile } from '@/hooks/use-poll-while';
import { store } from '@/routes/reservations/units/key-cards';
import { cancel } from '@/routes/settings/keyport/jobs';

type Cards = App.Data.Keyport.UnitKeyCardsData;
type Job = App.Data.Keyport.KeyportJobRowData;

const TIME: Intl.DateTimeFormatOptions = {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

const when = (iso: string) => new Date(iso).toLocaleString('en-GB', TIME);

/** Why staff cannot ask for a card now, or null when they can. */
function blocker(cards: Cards): string | null {
    if (!cards.hasDesk) {
        return 'No front-desk computer is set up. Add one in Settings > Key cards.';
    }

    if (!cards.deskOnline) {
        return 'The front-desk computer is offline. Open Keyport on it.';
    }

    return null;
}

/**
 * The key card of a stay at an eLock room. Staff press a button, then put a blank card on the reader at the front desk: the
 * desk computer writes it. The card is never written until a person does that.
 */
export function KeyCards({
    cards,
    reservationId,
    unitId,
}: {
    cards: Cards;
    reservationId: number;
    unitId: number;
}) {
    const [sending, setSending] = useState(false);
    const { gaveUp } = usePollWhile(cards.syncing, {
        only: ['keyCards'],
        every: 2000,
        giveUpAfter: 180000,
    });
    const reason = blocker(cards);
    const openJobs = cards.jobs.filter((job) => job.open);
    const history = cards.jobs.filter((job) => !job.open);

    function ask(lockId: number, type: string) {
        router.post(
            store.url({ reservation: reservationId, unit: unitId }),
            { door_lock_id: lockId, type },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
            },
        );
    }

    return (
        <section className="flex items-start gap-x-2">
            <h4 className="flex h-11 shrink-0 items-center gap-1.5 text-muted-foreground">
                <CreditCard aria-hidden className="size-4" />
                Key card
            </h4>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                {reason && cards.locks.length > 0 && (
                    <p className="text-sm text-warning">{reason}</p>
                )}
                {gaveUp && (
                    <p className="text-sm text-warning">
                        The card did not finish. Check the front-desk computer,
                        then try again.
                    </p>
                )}
                <ul className="flex flex-col gap-2">
                    {cards.locks.map((lock) => {
                        const busy = openJobs.length > 0;

                        return (
                            <li
                                key={lock.id}
                                className="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2.5"
                            >
                                <span className="min-w-0 flex-1 truncate font-medium">
                                    {lock.name}
                                    {lock.previous && (
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            Old room
                                        </span>
                                    )}
                                    {lock.stale && (
                                        <span className="ml-2 font-normal text-warning">
                                            Dates changed
                                        </span>
                                    )}
                                </span>
                                {!lock.previous && (
                                    <Button
                                        size="sm"
                                        variant={
                                            lock.stale ? 'outline' : 'default'
                                        }
                                        disabled={
                                            !cards.canMake || busy || sending
                                        }
                                        onClick={() =>
                                            ask(lock.id, 'guest_card.issue')
                                        }
                                    >
                                        {lock.hasCard
                                            ? 'Make another card'
                                            : 'Make key card'}
                                    </Button>
                                )}
                                {lock.hasCard && (
                                    <>
                                        {!lock.previous && (
                                            <Button
                                                size="sm"
                                                variant={
                                                    lock.stale
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                disabled={busy || sending}
                                                onClick={() =>
                                                    ask(
                                                        lock.id,
                                                        'guest_card.rewrite',
                                                    )
                                                }
                                            >
                                                Update dates
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant={
                                                lock.previous
                                                    ? 'default'
                                                    : 'ghost'
                                            }
                                            disabled={busy || sending}
                                            onClick={() =>
                                                ask(lock.id, 'guest_card.erase')
                                            }
                                        >
                                            Erase
                                        </Button>
                                    </>
                                )}
                            </li>
                        );
                    })}
                </ul>
                {openJobs.map((job) => (
                    <JobLine key={job.id} job={job} />
                ))}
                {history.length > 0 && (
                    <details className="text-sm text-muted-foreground">
                        <summary className="cursor-pointer select-none">
                            Earlier cards ({history.length})
                        </summary>
                        <ul className="mt-2 flex flex-col gap-1.5">
                            {history.map((job) => (
                                <JobLine key={job.id} job={job} />
                            ))}
                        </ul>
                    </details>
                )}
            </div>
        </section>
    );
}

/** One job: what it is, where it stands, and what to do next. A job still waiting can be cancelled. */
function JobLine({ job }: { job: Job }) {
    const waiting = job.status.value === 'pending';
    const writing = job.status.value === 'claimed';

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            {(waiting || writing) && <Spinner />}
            <span className="font-medium text-foreground">{job.typeLabel}</span>
            <StatusBadge status={job.status} />
            <span className="text-muted-foreground">{when(job.createdAt)}</span>
            {waiting && (
                <span className="text-foreground">
                    Put a blank card on the reader at the front desk.
                </span>
            )}
            {writing && (
                <span className="text-foreground">
                    Keep the card on the reader.
                </span>
            )}
            {job.message && <span className="text-warning">{job.message}</span>}
            {job.canCancel && (
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            cancel.url({ keyportJob: job.id }),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Cancel
                </Button>
            )}
        </div>
    );
}
