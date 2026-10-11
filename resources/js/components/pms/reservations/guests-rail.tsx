import { EnumSelect } from '@/components/pms/enum-select';
import { StatusBadge } from '@/components/pms/status-badge';
import { OPEN_STATUSES } from '@/components/pms/reservations/guest-rows';
import type { GuestGroup } from '@/components/pms/reservations/guest-rows';
import { cn } from '@/lib/utils';

function countLabel(group: GuestGroup): string {
    if (group.unitId === null) {
        return 'Booker';
    }

    return group.count === 0
        ? 'No guest yet'
        : `${group.count} ${group.count === 1 ? 'guest' : 'guests'}`;
}

/**
 * The units of the reservation for the Grouped view of the Guests tab: a rail from `lg` and a select below it. Picking
 * an entry makes no request; the table shows that unit's guests.
 */
export function GuestsRail({
    groups,
    activeKey,
    onSelect,
}: {
    groups: GuestGroup[];
    activeKey: string;
    onSelect: (key: string) => void;
}) {
    return (
        <>
            <div className="lg:hidden">
                <EnumSelect
                    aria-label="Choose unit"
                    options={groups.map((group) => ({
                        value: group.key,
                        label: `${group.label} · ${countLabel(group)}`,
                    }))}
                    value={activeKey}
                    onValueChange={onSelect}
                />
            </div>
            <nav
                aria-label="Choose unit"
                className="hidden h-fit flex-col gap-1 lg:flex"
            >
                {groups.map((group) => (
                    <button
                        key={group.key}
                        type="button"
                        aria-current={
                            group.key === activeKey ? 'true' : undefined
                        }
                        onClick={() => onSelect(group.key)}
                        className={cn(
                            'flex flex-col gap-1 rounded-lg border px-3 py-2 text-left text-sm hover:bg-muted',
                            group.key === activeKey &&
                                'border-primary bg-muted',
                        )}
                    >
                        <span className="font-medium">{group.label}</span>
                        <span className="flex items-center justify-between gap-2 text-muted-foreground">
                            <span>{countLabel(group)}</span>
                            {group.status &&
                                !OPEN_STATUSES.includes(group.status.value) && (
                                    <StatusBadge status={group.status} />
                                )}
                        </span>
                    </button>
                ))}
            </nav>
        </>
    );
}
