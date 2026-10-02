import {
    Ban,
    BriefcaseBusiness,
    Footprints,
    Globe,
    Network,
    Phone,
    RotateCw,
    Star,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Item = App.Data.Reservations.ReservationListItemData;

function IconWithTooltip({
    icon: Icon,
    label,
    className,
}: {
    icon: LucideIcon;
    label: string;
    className?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={label}
                        className="inline-flex shrink-0"
                    />
                }
            >
                <Icon aria-hidden className={cn('size-4', className)} />
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

const sourceIcons: Record<string, LucideIcon> = {
    direct: Globe,
    walk_in: Footprints,
    phone: Phone,
    channel: Network,
    agent: BriefcaseBusiness,
};

/** The booking source as an icon, with its label on hover. */
export function SourceIcon({ reservation }: { reservation: Item }) {
    return (
        <IconWithTooltip
            icon={sourceIcons[reservation.source] ?? Globe}
            label={reservation.sourceLabel}
            className="text-muted-foreground"
        />
    );
}

/** VIP, blacklisted, repeat guest and group markers. */
export function ReservationMarkers({ reservation }: { reservation: Item }) {
    return (
        <>
            {reservation.isGroup && (
                <IconWithTooltip
                    icon={Users}
                    label="Group"
                    className="text-muted-foreground"
                />
            )}
            {reservation.isVip && (
                <IconWithTooltip
                    icon={Star}
                    label="VIP"
                    className="fill-current text-warning"
                />
            )}
            {reservation.isBlacklisted && (
                <IconWithTooltip
                    icon={Ban}
                    label="Blacklisted"
                    className="text-destructive"
                />
            )}
            {reservation.isRepeat && (
                <IconWithTooltip
                    icon={RotateCw}
                    label="Repeat guest"
                    className="text-muted-foreground"
                />
            )}
        </>
    );
}

/**
 * The booker (groups only) and the reference. Channel bookings add the channel and its reference.
 */
export function reservationSubtitle(reservation: Item): string {
    return [
        reservation.isGroup || reservation.title !== reservation.bookerName
            ? reservation.bookerName
            : null,
        reservation.reference,
        reservation.channelName,
        reservation.externalReference,
    ]
        .filter(Boolean)
        .join(' · ');
}
