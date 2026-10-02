import { History } from 'lucide-react';
import { AuditTrail } from '@/components/pms/audit-trail';
import { EmptyState } from '@/components/pms/empty-state';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';

type Activity = App.Data.Reservations.ReservationActivityData;

/**
 * The Activity tab: the reservation's log, newest first, 25 to a page.
 */
export function ActivityTab({
    activity,
}: {
    activity: Illuminate.LengthAwarePaginator<number, Activity>;
}) {
    if (activity.data.length === 0) {
        return <EmptyState icon={History} title="No activity yet" />;
    }

    return (
        <div className="flex flex-col gap-3">
            <AuditTrail entries={activity.data} />
            <PaginationFooter pagination={toPaginationData(activity.meta)} />
        </div>
    );
}
