import { Link } from '@inertiajs/react';
import { BedDouble, Clock, ExternalLink } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';

type Room = {
    room_type_id: number;
    rate_plan_id: number;
    room: string;
    plan: string;
    total: string;
    currency: string;
};

type Rooms = {
    nights: number;
    adults: number;
    rooms: Room[];
};

type Quote = {
    sent: boolean;
    url: string;
    room: string;
    plan: string;
    arrival_date: string;
    departure_date: string;
    adults: number;
    total: string;
    currency: string;
};

/** A tool's output, which the server sends as a JSON string. Null when it is not there yet or is not JSON. */
export function toolOutput<T>(output: unknown): T | null {
    if (typeof output !== 'string') {
        return output && typeof output === 'object' ? (output as T) : null;
    }

    try {
        return JSON.parse(output) as T;
    } catch {
        return null;
    }
}

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Number(amount));
}

const shortDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

/**
 * The rooms `CheckRoomsAndPrices` found, drawn from its output so the price on screen always comes from the server, not from
 * the model's words. Choose sends the room as the guest's next message.
 */
export function RoomOptionsCard({
    output,
    disabled,
    onChoose,
}: {
    output: unknown;
    disabled: boolean;
    onChoose: (text: string) => void;
}) {
    const data = toolOutput<Rooms>(output);

    if (!data || data.rooms.length === 0) {
        return null;
    }

    const stay = `${data.nights} ${data.nights === 1 ? 'night' : 'nights'}`;

    return (
        <ul className="flex flex-col divide-y rounded-xl border bg-card">
            {data.rooms.map((room) => (
                <li
                    key={`${room.room_type_id}-${room.rate_plan_id}`}
                    className="flex items-center gap-3 p-3"
                >
                    <div className="min-w-0 flex-1">
                        <p className="font-medium text-pretty">{room.room}</p>
                        <p className="text-sm text-muted-foreground">
                            {room.plan} · {stay}
                        </p>
                    </div>
                    <p className="font-medium tabular-nums">
                        {money(room.total, room.currency)}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={disabled}
                        focusableWhenDisabled
                        onClick={() =>
                            onChoose(`${room.room}, ${room.plan}, please`)
                        }
                    >
                        Choose
                    </Button>
                </li>
            ))}
        </ul>
    );
}

/** The quote link `SendQuoteLink` made, drawn from its output. Nothing is booked until the guest books on the page. */
export function QuoteLinkCard({ output }: { output: unknown }) {
    const quote = toolOutput<Quote>(output);

    if (!quote?.sent) {
        return null;
    }

    return (
        <section
            aria-label="Your quote"
            className="flex flex-col gap-3 rounded-xl border bg-card p-4"
        >
            <div className="flex items-start gap-3">
                <BedDouble
                    className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <p className="font-medium text-pretty">{quote.room}</p>
                    <p className="text-sm text-muted-foreground">
                        {quote.plan}
                    </p>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {shortDate(quote.arrival_date)} to{' '}
                        {shortDate(quote.departure_date)} · {quote.adults}{' '}
                        {quote.adults === 1 ? 'adult' : 'adults'}
                    </p>
                </div>
                <p className="font-medium tabular-nums">
                    {money(quote.total, quote.currency)}
                </p>
            </div>
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Clock className="size-4" aria-hidden />
                You pay the whole stay when you book. The link works for 24
                hours.
            </p>
            <Link href={quote.url} className={buttonVariants()}>
                Open quote
                <ExternalLink data-icon="inline-end" />
            </Link>
        </section>
    );
}
