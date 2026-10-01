import { Ban, EyeOff, Star } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

function Flag({
    icon: Icon,
    label,
    className,
}: {
    icon: LucideIcon;
    label: string;
    className: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={label}
                        className="inline-flex"
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
 * VIP, anonymized and blacklisted markers as icons, each with a tooltip.
 */
export function GuestFlags({
    isVip,
    isAnonymized,
    isBlacklisted,
}: {
    isVip: boolean;
    isAnonymized: boolean;
    isBlacklisted: boolean;
}) {
    return (
        <>
            {isVip && (
                <Flag
                    icon={Star}
                    label="VIP"
                    className="fill-current text-warning"
                />
            )}
            {isAnonymized && (
                <Flag
                    icon={EyeOff}
                    label="Anonymized"
                    className="text-muted-foreground"
                />
            )}
            {isBlacklisted && (
                <Flag
                    icon={Ban}
                    label="Blacklisted"
                    className="text-destructive"
                />
            )}
        </>
    );
}
