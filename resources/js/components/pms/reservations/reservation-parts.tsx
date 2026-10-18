import { Ban, Info, RotateCw, Star, Users } from 'lucide-react';
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

/**
 * "Room total" with an info tooltip: the list and card figure counts room nights only, so a correct payment never
 * looks like an overpayment.
 */
export function RoomTotalLabel() {
    return (
        <span className="inline-flex items-center gap-1">
            Room total
            <IconWithTooltip
                icon={Info}
                label="Room nights only, before tax and extras."
                className="size-3.5 text-muted-foreground"
            />
        </span>
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
 * The booker (groups only) and the reference. OTA bookings add the source name. The external reference follows when set.
 */
export function reservationSubtitle(reservation: Item): string {
    return [
        reservation.isGroup || reservation.title !== reservation.bookerName
            ? reservation.bookerName
            : null,
        reservation.reference,
        reservation.source.type === 'ota' ? reservation.source.name : null,
        reservation.externalReference,
    ]
        .filter(Boolean)
        .join(' · ');
}
