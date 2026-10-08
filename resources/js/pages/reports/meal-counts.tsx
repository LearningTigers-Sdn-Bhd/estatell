import { Utensils } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { mealCounts } from '@/routes/reports';

type Row = App.Data.Reports.MealCountRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals;
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    presets: App.Enums.ReportDatePreset[];
    hasMealPlans: boolean;
    currency: App.Enums.Currency | null;
};

export default function MealCountsReport({ hasMealPlans, ...props }: Props) {
    return (
        <ReportScreen
            {...props}
            report="meal-counts"
            title="Meal counts"
            description="The guests to feed at each meal, from the rate plan meal inclusions of the stays. Future dates use the bookings as they are now."
            emptyIcon={Utensils}
            emptyTitle={
                hasMealPlans
                    ? 'No meals in these dates'
                    : 'No rate plan includes meals'
            }
            emptyDescription={
                hasMealPlans
                    ? 'Pick another range to see the meals to prepare.'
                    : 'Add a meal to a rate plan to see the guests to feed.'
            }
        />
    );
}

MealCountsReport.layout = {
    breadcrumbs: [{ title: 'Meal counts', href: mealCounts() }],
};
