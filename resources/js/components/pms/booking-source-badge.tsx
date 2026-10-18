import {
    BriefcaseBusiness,
    Building2,
    Footprints,
    Globe,
    Handshake,
    Mail,
    Megaphone,
    MessageCircle,
    Phone,
    Star,
    Ticket,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Source = App.Data.Reservations.BookingSourceBadgeData;

/** One Lucide component per `BookingSourceIcon` case. A missing case fails `tsc`. */
export const bookingSourceIcons: Record<
    App.Enums.BookingSourceIcon,
    LucideIcon
> = {
    phone: Phone,
    'message-circle': MessageCircle,
    mail: Mail,
    globe: Globe,
    users: Users,
    handshake: Handshake,
    'briefcase-business': BriefcaseBusiness,
    'building-2': Building2,
    footprints: Footprints,
    ticket: Ticket,
    megaphone: Megaphone,
    star: Star,
};

/** Literal class strings, so Tailwind sees every swatch pair. A missing case fails `tsc`. */
export const swatchClasses: Record<App.Enums.SwatchColor, string> = {
    gray: 'bg-swatch-gray text-swatch-gray-foreground',
    red: 'bg-swatch-red text-swatch-red-foreground',
    orange: 'bg-swatch-orange text-swatch-orange-foreground',
    amber: 'bg-swatch-amber text-swatch-amber-foreground',
    green: 'bg-swatch-green text-swatch-green-foreground',
    teal: 'bg-swatch-teal text-swatch-teal-foreground',
    blue: 'bg-swatch-blue text-swatch-blue-foreground',
    indigo: 'bg-swatch-indigo text-swatch-indigo-foreground',
    violet: 'bg-swatch-violet text-swatch-violet-foreground',
    pink: 'bg-swatch-pink text-swatch-pink-foreground',
};

export function swatchColorClass(color: App.Enums.SwatchColor): string {
    return swatchClasses[color];
}

const sizeClasses = {
    sm: { box: 'size-5', icon: 'size-3' },
    default: { box: 'size-6', icon: 'size-3.5' },
} as const;

/**
 * A booking source as a small rounded square: an icon or one or two letters on a swatch color. The source name is the
 * accessible name and the tooltip, because color never carries meaning alone.
 */
export function BookingSourceBadge({
    source,
    size = 'default',
    className,
}: {
    source: Pick<Source, 'name' | 'icon' | 'letters' | 'color'>;
    size?: 'sm' | 'default';
    className?: string;
}) {
    const Icon = source.icon === null ? null : bookingSourceIcons[source.icon];

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={source.name}
                        className={cn(
                            'inline-flex shrink-0 items-center justify-center rounded-md text-xs leading-none font-semibold select-none',
                            sizeClasses[size].box,
                            swatchClasses[source.color],
                            className,
                        )}
                    />
                }
            >
                {Icon ? (
                    <Icon aria-hidden className={sizeClasses[size].icon} />
                ) : (
                    <span aria-hidden>{source.letters}</span>
                )}
            </TooltipTrigger>
            <TooltipContent>{source.name}</TooltipContent>
        </Tooltip>
    );
}
