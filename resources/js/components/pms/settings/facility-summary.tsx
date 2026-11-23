import {
    ClipboardList,
    Clock,
    Hash,
    Landmark,
    Tag,
    Users,
    Waypoints,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatMoney } from '@/components/pms/money-text';
import { Separator } from '@/components/ui/separator';

type Facility = App.Data.Facilities.FacilityData;

function SummaryRow({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="flex shrink-0 items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
            <dd className="min-w-0 text-right wrap-break-word">{children}</dd>
        </div>
    );
}

/**
 * The summary rail of the facility page, from the saved facility. It changes after a save, not while typing.
 */
export function FacilitySummary({
    facility,
    currency,
}: {
    facility: Facility;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3 pt-2">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <ClipboardList aria-hidden className="size-4" />
                </span>
                <h2 className="text-base font-medium">Summary</h2>
            </div>
            <Separator />
            <dl className="flex flex-col gap-2">
                <SummaryRow icon={Waypoints} label="Booked by">
                    {facility.bookedByLabel}
                </SummaryRow>
                <SummaryRow icon={Users} label="How many">
                    <span className="tabular-nums">
                        {facility.capacity} {facility.unitLabel}
                    </span>
                </SummaryRow>
                <SummaryRow icon={Tag} label="Rate">
                    {facility.amount === null ? (
                        <span className="text-muted-foreground">
                            Charge by hand
                        </span>
                    ) : (
                        <span className="tabular-nums">
                            {formatMoney(facility.amount, currency)}{' '}
                            <span className="text-muted-foreground">
                                {facility.rateBasisLabel.toLowerCase()}
                            </span>
                        </span>
                    )}
                </SummaryRow>
                <SummaryRow icon={Landmark} label="Taxes">
                    {facility.taxNames.length > 0 ? (
                        facility.taxNames.join(', ')
                    ) : (
                        <span className="text-muted-foreground">None</span>
                    )}
                </SummaryRow>
                <SummaryRow icon={Hash} label="Code">
                    <span className="font-mono text-xs">{facility.code}</span>
                </SummaryRow>
                <SummaryRow icon={Clock} label="Sessions">
                    {facility.sessionCount > 0 ? (
                        <span className="tabular-nums">
                            {facility.sessionCount}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">Any time</span>
                    )}
                </SummaryRow>
            </dl>
        </div>
    );
}
