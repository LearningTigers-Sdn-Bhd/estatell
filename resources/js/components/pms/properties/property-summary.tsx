import {
    Ban,
    Building2,
    CalendarPlus,
    CircleDot,
    ClipboardList,
    Handshake,
    Hash,
    Mail,
    Rocket,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Separator } from '@/components/ui/separator';

type Property = App.Data.Platform.AdminPropertyData;

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
 * The summary rail of the admin and partner property pages: a heading with an icon box, then the rows.
 */
export function PropertySummary({
    property,
    showPartner = false,
}: {
    property: Property;
    showPartner?: boolean;
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
                <SummaryRow icon={Hash} label="Code">
                    <span className="tabular-nums">{property.code}</span>
                </SummaryRow>
                <SummaryRow icon={User} label="Owner">
                    {property.ownerName ?? (
                        <span className="text-muted-foreground">No owner</span>
                    )}
                </SummaryRow>
                {property.ownerEmail && (
                    <SummaryRow icon={Mail} label="Owner email">
                        <span className="break-all">{property.ownerEmail}</span>
                    </SummaryRow>
                )}
                {showPartner && (
                    <SummaryRow icon={Handshake} label="Partner">
                        {property.partnerName ?? 'Direct'}
                    </SummaryRow>
                )}
            </dl>
            <Separator />
            <dl className="flex flex-col gap-2">
                <SummaryRow icon={CircleDot} label="Status">
                    <StatusBadge status={property.status} />
                </SummaryRow>
                <SummaryRow icon={Building2} label="Type">
                    {property.type ?? '—'}
                </SummaryRow>
                <SummaryRow icon={CalendarPlus} label="Created">
                    <DateText date={property.createdAt} />
                </SummaryRow>
                <SummaryRow icon={Rocket} label="Live since">
                    <DateText date={property.wentLiveAt} />
                </SummaryRow>
                {property.suspendedAt && (
                    <SummaryRow icon={Ban} label="Suspended">
                        <DateText date={property.suspendedAt} />
                    </SummaryRow>
                )}
            </dl>
        </div>
    );
}
