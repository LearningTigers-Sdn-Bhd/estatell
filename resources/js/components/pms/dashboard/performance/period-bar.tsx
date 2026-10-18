import { router, usePage } from '@inertiajs/react';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    monthLabel,
    periodSentence,
} from '@/components/pms/dashboard/performance/format';
import { dashboard } from '@/routes';

/** A period change reloads only these props. */
const only = ['period', 'performance'];

/**
 * The presets as one toggle group, the month picker and the line that states both ranges in words. Every choice visits
 * a URL, so the period is in the URL. A picked month leaves no preset pressed; pressing the open preset again does nothing.
 */
export function PeriodBar({
    period,
}: {
    period: App.Data.Dashboard.PerformancePeriodData;
}) {
    const code = usePage().props.property?.code ?? '';
    const visit = (query: Record<string, string>) =>
        router.get(
            dashboard.url(code, { query: { tab: 'performance', ...query } }),
            {},
            { only, preserveState: true, preserveScroll: true },
        );
    const monthItems = period.months.map((month) => ({
        value: month,
        label: monthLabel(month),
    }));

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2 overflow-x-auto pb-1 md:flex-wrap md:overflow-visible">
                <span
                    id="performance-period-label"
                    className="shrink-0 text-sm text-muted-foreground"
                >
                    Period
                </span>
                <ToggleGroup
                    variant="outline"
                    spacing={0}
                    value={period.preset === null ? [] : [period.preset]}
                    onValueChange={(value) => {
                        if (value[0]) {
                            visit({ period: value[0] });
                        }
                    }}
                    aria-labelledby="performance-period-label"
                    className="shrink-0"
                >
                    {period.presets.map((preset) => (
                        <ToggleGroupItem
                            key={preset.value}
                            value={preset.value}
                        >
                            {preset.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                {monthItems.length > 0 && (
                    <Select
                        items={monthItems}
                        value={period.month}
                        onValueChange={(month) => {
                            if (month) {
                                visit({ month });
                            }
                        }}
                    >
                        <SelectTrigger
                            className="w-36 shrink-0"
                            aria-label="Pick a month"
                        >
                            <SelectValue placeholder="e.g. Sep 2026" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {monthItems.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                )}
            </div>
            <p className="text-sm text-muted-foreground">
                {periodSentence(period)}
            </p>
        </div>
    );
}
