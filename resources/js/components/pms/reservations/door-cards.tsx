import { router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import { when } from '@/components/pms/reservations/door-pins';
import { ScanDoorCardButton } from '@/components/pms/reservations/scan-door-card-button';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { destroy } from '@/routes/reservations/units/door-cards';

type Card = App.Data.DoorLocks.DoorCardData;

function CardRow({
    card,
    reservationId,
    unitId,
}: {
    card: Card;
    reservationId: number;
    unitId: number;
}) {
    const [removing, setRemoving] = useState(false);

    return (
        <li className="flex flex-col gap-1 rounded-lg border bg-card px-3 py-1.5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span className="font-medium" title={card.cardNumber}>
                    Card ending {card.cardNumber.slice(-4)}
                </span>
                <StatusBadge status={card.status} />
                <span className="text-sm text-muted-foreground">
                    {card.lockName} · until {when(card.endsAt)}
                </span>
                {card.canRemove && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                        disabled={removing}
                        onClick={() =>
                            router.delete(
                                destroy.url({
                                    reservation: reservationId,
                                    unit: unitId,
                                    doorCard: card.id,
                                }),
                                {
                                    preserveScroll: true,
                                    onStart: () => setRemoving(true),
                                    onFinish: () => setRemoving(false),
                                },
                            )
                        }
                    >
                        {removing ? 'Removing…' : 'Remove'}
                    </Button>
                )}
            </div>
            {card.status.value === 'failed' && card.error && (
                <p className="text-sm text-destructive">{card.error}</p>
            )}
        </li>
    );
}

/**
 * The guest cards of the stay. Staff scan a card on the desk reader to add it; it opens the stay's doors until departure.
 */
export function DoorCards({
    cards,
    reservationId,
    unitId,
}: {
    cards: Card[];
    reservationId: number;
    unitId: number;
}) {
    return (
        <section className="flex items-start gap-x-2">
            <h4 className="flex h-11 shrink-0 items-center gap-1.5 text-muted-foreground">
                <CreditCard aria-hidden className="size-4" />
                Key card
            </h4>
            <div className="flex min-w-0 flex-1 flex-col items-start gap-2">
                {cards.length > 0 && (
                    <ul className="flex w-full flex-col gap-2">
                        {cards.map((card) => (
                            <CardRow
                                key={card.id}
                                card={card}
                                reservationId={reservationId}
                                unitId={unitId}
                            />
                        ))}
                    </ul>
                )}
                <div className="flex h-11 items-center gap-3">
                    <ScanDoorCardButton
                        reservationId={reservationId}
                        unitId={unitId}
                    />
                    {cards.length === 0 && (
                        <span className="text-sm text-muted-foreground">
                            No card yet
                        </span>
                    )}
                </div>
            </div>
        </section>
    );
}
