import { Link, router, usePage } from '@inertiajs/react';
import { buttonVariants } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from 'cn';
import {
    monthLabel,
    periodSentence,
} from '@/components/pms/dashboard/performance/format';
import { dashboard } from '@/routes';

/** A period change reloads only these props. */
const only = ['period', 'performance'];

/**
 * The presets, the month picker and the line that states both ranges in words. Every choice is a link, so the period
 * is in the URL.
 */
export function PeriodBar({
    period,
}: {
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    const code = usePage().props.property?.code ?? '';
    const href = (query: Record<string, string>) =>
        dashboard.url(code, { query: { tab: 'performance', ...query } });
    const monthItems = period.months.map((month) => ({
        value: month,
        label: monthLabel(month),
    }));

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2 overflow-x-auto pb-1 md:flex-wrap md:overflow-visible">
                {period.presets.map((preset) => (
                    <Link
                        key={preset.value}
                        href={href({ period: preset.value })}
                        only={only}
                        preserveState
                        preserveScroll
                        aria-current={
                            period.preset === preset.value ? 'true' : undefined
                        }
                        className={cn(
                            buttonVariants({
                                variant:
                                    period.preset === preset.value
                                        ? 'default'
                                        : 'outline',
                                size: 'sm',
                            }),
                            'shrink-0',
                        )}
                    >
                        {preset.label}
                    </Link>
                ))}
                <Select
                    items={monthItems}
                    value={period.month}
                    disabled={monthItems.length === 0}
                    onValueChange={(month) => {
                        if (month) {
                            router.get(
                                href({ month }),
                                {},
                                {
                                    only,
                                    preserveState: true,
                                    preserveScroll: true,
                                },
                            );
                        }
                    }}
                >
                    <SelectTrigger
                        size="sm"
                        className="w-36 shrink-0"
                        aria-label="Pick a month"
                    >
                        <SelectValue placeholder="e.g. Sep 2026" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {monthItems.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
            </div>
            <p className="text-sm text-muted-foreground">
                {periodSentence(period)}
            </p>
        </div>
    );
}
