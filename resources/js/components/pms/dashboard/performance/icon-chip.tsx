import type { LucideIcon } from 'lucide-react';

/**
 * The soft primary icon chip beside a Performance card title, the same as the Today stat tiles.
 */
export function IconChip({ icon: Icon }: { icon: LucideIcon }) {
    return (
        <span
            aria-hidden
            className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/5 text-primary"
        >
            <Icon className="size-5" />
        </span>
    );
}
