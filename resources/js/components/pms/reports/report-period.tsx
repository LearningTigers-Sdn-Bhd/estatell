import { DateText } from '@/components/pms/date-text';

/**
 * The first column of a report that groups by day or month: `period` is `Y-m-d` for a day and `Y-m` for a month.
 */
export function ReportPeriod({
    period,
    group,
}: {
    period: string;
    group: App.Enums.ReportGroup;
}) {
    if (group === 'month') {
        return (
            <time dateTime={period} className="tabular-nums">
                {new Date(`${period}-01T00:00:00`).toLocaleDateString('en-GB', {
                    month: 'short',
                    year: 'numeric',
                })}
            </time>
        );
    }

    return <DateText date={period} />;
}
