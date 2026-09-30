import { Ban, Link2 } from 'lucide-react';

/**
 * What the markers in a rate calendar cell mean.
 */
export function RateCalendarLegend() {
    return (
        <dl className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-muted-foreground">
            <div className="flex items-center gap-1.5">
                <dt>
                    <Ban aria-hidden className="size-3 text-destructive" />
                </dt>
                <dd>Stop sell</dd>
            </div>
            <div className="flex items-center gap-1.5">
                <dt className="text-warning">CTA</dt>
                <dd>Closed to arrival</dd>
            </div>
            <div className="flex items-center gap-1.5">
                <dt className="text-warning">CTD</dt>
                <dd>Closed to departure</dd>
            </div>
            <div className="flex items-center gap-1.5">
                <dt>Min 2</dt>
                <dd>Minimum nights</dd>
            </div>
            <div className="flex items-center gap-1.5">
                <dt>
                    <Link2 aria-hidden className="size-3 text-info" />
                </dt>
                <dd>Price follows the parent plan</dd>
            </div>
        </dl>
    );
}
