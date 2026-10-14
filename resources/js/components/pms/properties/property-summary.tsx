import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { StatusBadge } from '@/components/pms/status-badge';

type Property = App.Data.Platform.AdminPropertyData;

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/**
 * The summary card body, shared by the admin and partner property pages.
 */
export function PropertySummary({
    property,
    showPartner = false,
}: {
    property: Property;
    showPartner?: boolean;
}) {
    return (
        <dl className="flex flex-col gap-3">
            <SummaryRow label="Code">
                <span className="tabular-nums">{property.code}</span>
            </SummaryRow>
            <SummaryRow label="Owner">
                {property.ownerName ? (
                    <span className="flex flex-col">
                        <span>{property.ownerName}</span>
                        <span className="text-xs text-muted-foreground">
                            {property.ownerEmail}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">No owner</span>
                )}
            </SummaryRow>
            {showPartner && (
                <SummaryRow label="Partner">
                    {property.partnerName ?? 'Direct'}
                </SummaryRow>
            )}
            <SummaryRow label="Status">
                <StatusBadge status={property.status} />
            </SummaryRow>
            <SummaryRow label="Type">{property.type ?? '—'}</SummaryRow>
            <SummaryRow label="Created">
                <DateText date={property.createdAt} />
            </SummaryRow>
            <SummaryRow label="Live since">
                <DateText date={property.wentLiveAt} />
            </SummaryRow>
            {property.suspendedAt && (
                <SummaryRow label="Suspended">
                    <DateText date={property.suspendedAt} />
                </SummaryRow>
            )}
        </dl>
    );
}
