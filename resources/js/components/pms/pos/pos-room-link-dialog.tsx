import { router } from '@inertiajs/react';
import { BedDouble, Search, Unlink } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { inHouseGuests } from '@/routes/pos';
import { room as attachRoomRoute } from '@/routes/pos/orders';

export type InHouseGuest = {
    reservation_unit_id: number;
    reservation_reference: string;
    room_number: string;
    guest_name: string;
    can_post_charges: boolean;
    meals?: {
        meal_period_id: number;
        meal_period_name: string;
        entitled: number;
        remaining: number;
    }[];
};

/**
 * Search and link an in-house guest stay to an open POS order (for included meal redemption or room association).
 */
export function PosRoomLinkDialog({
    open,
    onClose,
    orderId,
    currentUnitId,
    currentLabel,
}: {
    open: boolean;
    onClose: () => void;
    orderId: number;
    currentUnitId: number | null;
    currentLabel: string | null;
}) {
    const [query, setQuery] = useState('');
    const [guests, setGuests] = useState<InHouseGuest[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setError(null);
            fetch(
                inHouseGuests.url(undefined, {
                    query: query.trim() === '' ? {} : { q: query.trim() },
                }),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('load_failed');
                    }

                    return response.json() as Promise<{
                        guests: InHouseGuest[];
                    }>;
                })
                .then((body) => {
                    setGuests(body.guests);
                    setLoading(false);
                })
                .catch((err: unknown) => {
                    if (
                        err instanceof DOMException &&
                        err.name === 'AbortError'
                    ) {
                        return;
                    }

                    setError('Could not load in-house guests. Try again.');
                    setLoading(false);
                });
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query]);

    function pick(unitId: number | null) {
        router.post(
            attachRoomRoute.url({ order: orderId }),
            { reservation_unit_id: unitId },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onClose(),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Link room or guest</DialogTitle>
                    <DialogDescription>
                        Attach an in-house stay to redeem included meal
                        entitlements on this order.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-3">
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            className="h-11 pl-9"
                            placeholder="Search room number, guest name or booking ref"
                            aria-label="Search in-house guests"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            autoFocus
                        />
                    </div>
                    {loading && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner />
                            Searching in-house guests...
                        </p>
                    )}
                    {error !== null && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {!loading && error === null && guests.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No in-house guests found.
                        </p>
                    )}
                    <div className="flex max-h-72 flex-col gap-2 overflow-y-auto">
                        {guests.map((guest) => {
                            const selected =
                                currentUnitId === guest.reservation_unit_id;

                            return (
                                <Button
                                    key={guest.reservation_unit_id}
                                    type="button"
                                    variant={selected ? 'default' : 'outline'}
                                    className="h-auto min-h-12 flex-col items-start gap-1 py-2.5 text-left"
                                    disabled={saving}
                                    onClick={() =>
                                        pick(guest.reservation_unit_id)
                                    }
                                >
                                    <span className="flex w-full items-center justify-between gap-2">
                                        <span className="flex items-center gap-1.5 font-medium">
                                            <BedDouble
                                                className="size-4 shrink-0"
                                                aria-hidden
                                            />
                                            {guest.room_number
                                                ? `Room ${guest.room_number}`
                                                : guest.reservation_reference}{' '}
                                            · {guest.guest_name}
                                        </span>
                                        {!guest.can_post_charges && (
                                            <Badge variant="outline">
                                                Cash only
                                            </Badge>
                                        )}
                                    </span>
                                    {guest.meals && guest.meals.length > 0 && (
                                        <span className="flex flex-wrap gap-1.5 text-xs">
                                            {guest.meals.map((meal) => (
                                                <Badge
                                                    key={meal.meal_period_id}
                                                    variant={
                                                        meal.remaining > 0
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {meal.meal_period_name}:{' '}
                                                    {meal.remaining}/
                                                    {meal.entitled} left
                                                </Badge>
                                            ))}
                                        </span>
                                    )}
                                </Button>
                            );
                        })}
                    </div>
                </div>
                <DialogFooter className="flex-row justify-between gap-2">
                    {currentUnitId !== null ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={saving}
                            onClick={() => pick(null)}
                        >
                            <Unlink data-icon="inline-start" />
                            Unlink {currentLabel ?? 'room'}
                        </Button>
                    ) : (
                        <span />
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        className="min-h-11"
                        onClick={onClose}
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
