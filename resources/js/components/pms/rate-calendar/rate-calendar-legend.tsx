import { Ban, Info, Link2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * A Legend button that opens what the markers in a rate calendar cell mean.
 */
export function RateCalendarLegend() {
    return (
        <Popover>
            <PopoverTrigger render={<Button type="button" variant="outline" />}>
                <Info data-icon="inline-start" />
                Legend
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64">
                <dl className="flex flex-col gap-2 text-sm">
                    <div className="flex items-center gap-2">
                        <dt className="w-10">
                            <Ban
                                aria-hidden
                                className="size-4 text-destructive"
                            />
                        </dt>
                        <dd>Stop sell</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <dt className="w-10 text-xs text-warning">CTA</dt>
                        <dd>Closed to arrival</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <dt className="w-10 text-xs text-warning">CTD</dt>
                        <dd>Closed to departure</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <dt className="w-10 text-xs text-muted-foreground">
                            Min 2
                        </dt>
                        <dd>Minimum nights</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <dt className="w-10">
                            <Link2 aria-hidden className="size-4 text-info" />
                        </dt>
                        <dd>Price follows the parent plan</dd>
                    </div>
                </dl>
            </PopoverContent>
        </Popover>
    );
}
