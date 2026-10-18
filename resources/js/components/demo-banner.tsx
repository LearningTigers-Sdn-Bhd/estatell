import { usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';

/**
 * Shown on every staff and account page of the demo environment. The data is shared with other visitors and is
 * rebuilt every night, so the banner cannot be dismissed.
 */
export function DemoBanner() {
    const { isDemo } = usePage().props;

    if (!isDemo) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex items-center gap-2 border-b border-info/30 bg-info/10 px-4 py-1.5 text-sm"
        >
            <Info className="size-4 shrink-0 text-info" />
            <p className="min-w-0 flex-1">
                <span className="font-medium">Demo</span> · Shared with other
                visitors · Resets every night · Don't enter real guest details
            </p>
        </div>
    );
}
