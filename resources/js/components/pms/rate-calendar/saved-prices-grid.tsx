import { useMemo } from 'react';
import type { Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle, GridRow } from '@/components/pms/grid/date-grid';
import { indexCells } from './calendar-model';
import { RateCell } from './rate-calendar-grid';

type RoomType = App.Data.Rates.RateCalendarRoomTypeData;
type SavedRow = GridRow & { kind: 'row'; roomType: RoomType };

/**
 * The saved prices of one plan: a row for each room type and a column for each date. Read only. The grid sizes
 * itself to the window and scrolls inside its panel.
 */
export function SavedPricesGrid({
    gridRef,
    roomTypes,
    dates,
    today,
    weekendDays,
    roomTypeId,
}: {
    gridRef: Ref<DateGridHandle>;
    roomTypes: RoomType[];
    dates: string[];
    today: string;
    /** ISO weekdays whose nights are weekend nights, from the property settings. */
    weekendDays: number[];
    /** A room type id as text, or "" for all. */
    roomTypeId: string;
}) {
    const columns = useMemo(
        () => buildDateColumns(dates, today, weekendDays),
        [dates, today, weekendDays],
    );
    const rows = useMemo(
        () =>
            roomTypes
                .filter(
                    (roomType) =>
                        roomTypeId === '' || String(roomType.id) === roomTypeId,
                )
                .map((roomType): SavedRow => ({
                    key: `row-${roomType.id}`,
                    kind: 'row',
                    roomType,
                })),
        [roomTypes, roomTypeId],
    );
    const cellsByRoomType = useMemo(
        () =>
            new Map(
                roomTypes.map((roomType) => [
                    roomType.id,
                    indexCells(roomType.plans[0]),
                ]),
            ),
        [roomTypes],
    );

    return (
        <DateGrid<SavedRow>
            ref={gridRef}
            aria-label="Saved prices"
            className="rounded-none"
            rows={rows}
            columns={columns}
            renderLabel={(row) => (
                <span className="truncate text-sm font-medium">
                    {row.roomType.name}
                </span>
            )}
            renderCell={(row, column) => {
                const plan = row.roomType.plans[0];

                return (
                    <RateCell
                        cell={cellsByRoomType
                            .get(row.roomType.id)
                            ?.get(column.date)}
                        derived={plan.derived}
                        staged={false}
                        stayNights={
                            plan.pricingBasis === 'package'
                                ? plan.fixedNights
                                : null
                        }
                    />
                );
            }}
        />
    );
}
