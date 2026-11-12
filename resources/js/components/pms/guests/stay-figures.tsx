import { BedDouble, CalendarClock, Moon, Store, Wallet } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { StatTile } from '@/components/pms/stat-tile';

/**
 * The five stay figures above the guest page tabs: stays, nights, spend, last visit and source. The server counts them.
 */
export function StayFigures({
    summary,
    currency,
}: {
    summary: App.Data.Guests.GuestStaySummaryData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const noStays = summary.stays === 0;

    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <StatTile
                title="Stays"
                icon={BedDouble}
                figure={summary.stays}
                note={
                    noStays
                        ? 'No stays yet'
                        : `${summary.staysLastYear} in the last 12 months`
                }
            />
            <StatTile
                title="Nights"
                icon={Moon}
                figure={summary.nights}
                note={
                    summary.dayUses === 0
                        ? 'Overnight stays'
                        : `${summary.dayUses} day ${summary.dayUses === 1 ? 'use' : 'uses'}`
                }
            />
            <StatTile
                title="Spend"
                icon={Wallet}
                figure={
                    <MoneyText amount={summary.spend} currency={currency} />
                }
                note="Without tax"
            />
            <StatTile
                title="Last visit"
                icon={CalendarClock}
                figure={
                    summary.lastVisit ? (
                        <DateText date={summary.lastVisit} />
                    ) : (
                        '—'
                    )
                }
                note={
                    summary.inHouse
                        ? 'In house now'
                        : noStays
                          ? 'No stays yet'
                          : 'Last departure'
                }
            />
            <StatTile
                title="Source"
                icon={Store}
                figure={
                    <span className="block truncate">
                        {summary.source ?? '—'}
                    </span>
                }
                note={summary.sourceKind ?? 'No stays yet'}
            />
        </div>
    );
}
