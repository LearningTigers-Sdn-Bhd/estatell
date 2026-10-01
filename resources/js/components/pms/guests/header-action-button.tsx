import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * An outline button for the page header with a label. Only the icon carries the color.
 */
export function HeaderActionButton({
    label,
    icon: Icon,
    iconClassName,
    loading = false,
    type = 'button',
    disabled,
    onClick,
}: {
    label: string;
    icon: LucideIcon;
    /** Text color class for the icon, for example `text-destructive`. */
    iconClassName?: string;
    loading?: boolean;
    type?: 'button' | 'submit';
    disabled?: boolean;
    onClick?: () => void;
}) {
    return (
        <Button
            type={type}
            variant="outline"
            disabled={disabled}
            focusableWhenDisabled
            onClick={onClick}
        >
            {loading ? (
                <Spinner data-icon="inline-start" />
            ) : (
                <Icon
                    aria-hidden
                    data-icon="inline-start"
                    className={cn(iconClassName)}
                />
            )}
            {label}
        </Button>
    );
}
