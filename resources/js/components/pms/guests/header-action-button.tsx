import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * A labeled page-header button. Outline by default, with a destructive variant for irreversible actions.
 */
export function HeaderActionButton({
    label,
    icon: Icon,
    iconClassName,
    variant = 'outline',
    loading = false,
    type = 'button',
    disabled,
    onClick,
}: {
    label: string;
    icon: LucideIcon;
    /** Text color class for the icon, for example `text-destructive`. */
    iconClassName?: string;
    variant?: 'outline' | 'destructive';
    loading?: boolean;
    type?: 'button' | 'submit';
    disabled?: boolean;
    onClick?: () => void;
}) {
    return (
        <Button
            type={type}
            variant={variant}
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
