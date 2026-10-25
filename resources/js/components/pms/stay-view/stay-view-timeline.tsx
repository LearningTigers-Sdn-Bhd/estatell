import { useMemo, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { isCellBookable, takenNights } from './bookable-cell';
import { BlockBar } from './block-bar';
import { EmptyCell } from './cell-actions';
import { DayUseBar } from './day-use-bar';
import { filterStays } from './filter-stays';
import { buildRows, toggleCollapsed } from './group-rows';
import { InventoryFooterCell, InventoryFooterLabel } from './inventory-footer';
import {
    NightlyRateCell,
    NightlyRateHoverCard,
    NightlyRateLabel,
} from './nightly-rate-row';
import {
    planChoices,
    plansOnDate,
    pricesByRoomType,
    resolveChoice,
} from './nightly-rate';
import type { RateChoice } from './nightly-rate';
import {
    blockBarInputs,
    dayUseCellKey,
    dayUseMarkers,
    projectBars,
    stayBarInputs,
} from './project-bars';
import { GroupLabel, RoomLabel } from './room-row';
import { StayBar } from './stay-bar';
import { summarizeInventory } from './summarize-inventory';

type Filters = App.Data.StayView.StayViewFiltersData;

const ROW_HEIGHT = 48;

/**
 * The Timeline View grid: rooms and beds by night, with stay and block bars drawn over the cells. Filtering, grouping,
 * the bar positions, the footer counts and the rate row are all worked out here from the loaded props.
 */
export function StayViewTimeline({
    gridRef,
    window,
    weekendDays,
    rooms,
    stays,
    blocks,
    dayUses,
    rates,
    filters,
    showRates,
    rateChoice,
    currency,
    canViewReservations,
    canManageReservations,
    onOpenStay,
    corner,
}: {
    gridRef: Ref<DateGridHandle>;
    window: App.Data.StayView.StayViewWindowData;
    weekendDays: number[];
    rooms: App.Data.StayView.RoomRowData[];
    stays: App.Data.StayView.StayData[];
    blocks: App.Data.StayView.BlockData[];
    dayUses: App.Data.StayView.DayUseData[];
    rates: App.Data.StayView.RatePriceRowData[];
    filters: Filters;
    showRates: boolean;
    rateChoice: RateChoice;
    currency: App.Data.Property.CurrencyData | null;
    canViewReservations: boolean;
    canManageReservations: boolean;
    onOpenStay: (unitId: number) => void;
    /** The control in the top-left corner of the date header. */
    corner?: ReactNode;
}) {
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());
    const [hoveredReservation, setHoveredReservation] = useState<number | null>(
        null,
    );

    const dates = useMemo(() => {
        const list: string[] = [];
        const [year, month, day] = window.from.split('-').map(Number);

        for (let offset = 0; ; offset += 1) {
            const date = new Date(Date.UTC(year, month - 1, day + offset))
                .toISOString()
                .slice(0, 10);

            if (date > window.to) {
                break;
            }

            list.push(date);
        }

        return list;
    }, [window.from, window.to]);
    const columns = useMemo(
        () => buildDateColumns(dates, window.today, weekendDays),
        [dates, window.today, weekendDays],
    );

    const visible = useMemo(
        () => filterStays(rooms, stays, filters),
        [rooms, stays, filters],
    );
    const rows = useMemo(
        () => buildRows(visible.rooms, filters.groupBy, collapsed),
        [visible.rooms, filters.groupBy, collapsed],
    );
    const roomByKey = useMemo(
        () => new Map(visible.rooms.map((room) => [room.key, room])),
        [visible.rooms],
    );
    const bars = useMemo(() => {
        const stayById = new Map(
            visible.stays.map((stay) => [stay.unitId, stay]),
        );
        const markers = dayUseMarkers(dayUses, visible.rooms, dates);
        const dayUseCells = new Set(
            markers.map((marker) =>
                dayUseCellKey(marker.rowKey, dates[marker.column]),
            ),
        );
        const stayBars = projectBars(
            stayBarInputs(visible.stays),
            dates,
            dayUseCells,
        ).map((bar) => ({
            bar,
            stay: stayById.get(Number(bar.id.split('-')[1])),
        }));
        const blockList = blockBarInputs(blocks, visible.rooms);
        const blockById = new Map(blocks.map((block) => [block.id, block]));
        const blockBars = projectBars(blockList, dates).map((bar) => ({
            bar,
            block: blockById.get(Number(bar.id.split('-')[1])),
        }));

        return { stayBars, blockBars, markers };
    }, [visible.stays, visible.rooms, blocks, dayUses, dates]);
    const inventory = useMemo(
        () =>
            new Map(
                summarizeInventory(
                    visible.rooms,
                    visible.stays,
                    blocks,
                    dates,
                ).map((day) => [day.date, day]),
            ),
        [visible.rooms, visible.stays, blocks, dates],
    );

    const taken = useMemo(
        () => takenNights(visible.rooms, visible.stays, blocks, dates),
        [visible.rooms, visible.stays, blocks, dates],
    );

    const choice = useMemo(
        () => resolveChoice(rates, rateChoice),
        [rates, rateChoice],
    );
    const pricesByType = useMemo(
        () => pricesByRoomType(rates, choice),
        [rates, choice],
    );
    const planName =
        planChoices(rates).find((plan) => plan.id === choice.ratePlanId)
            ?.name ?? null;
    const priceCell = (
        roomTypeId: number,
        roomTypeName: string,
        date: string,
    ) => (
        <NightlyRateHoverCard
            roomTypeName={roomTypeName}
            date={date}
            plans={plansOnDate(rates, roomTypeId, date)}
            chosenPlanId={choice.ratePlanId}
            currency={currency}
        >
            <NightlyRateCell
                day={pricesByType.get(roomTypeId)?.get(date)}
                currency={currency}
            />
        </NightlyRateHoverCard>
    );
    const hasRates = showRates && rates.length > 0;
    // A price is only right next to the rooms it is for: in the heading of a room type group, or in one row when the
    // rooms on screen are all of one room type.
    const priceInGroups = hasRates && filters.groupBy === 'room_type';
    const shownTypes = new Set(visible.rooms.map((room) => room.roomTypeId));
    const pinnedType =
        hasRates && !priceInGroups && shownTypes.size === 1
            ? visible.rooms[0]
            : null;

    return (
        <DateGrid
            ref={gridRef}
            fill
            stickyGroups
            aria-label="Timeline View"
            className="rounded-none border-x-0"
            labelWidth={288}
            renderCorner={() => corner}
            groupHeight={56}
            footerHeight={64}
            rows={rows}
            columns={columns}
            rowHeight={ROW_HEIGHT}
            renderLabel={(row) =>
                row.kind === 'group' ? (
                    <GroupLabel
                        row={row}
                        onToggle={(key) =>
                            setCollapsed((current) =>
                                toggleCollapsed(current, key),
                            )
                        }
                    />
                ) : (
                    <RoomLabel room={row.room} />
                )
            }
            renderCell={(row, column) =>
                row.kind === 'row' &&
                isCellBookable({
                    canManageReservations,
                    rowKey: row.key,
                    date: column.date,
                    businessDate: window.businessDate,
                    taken,
                }) ? (
                    <EmptyCell room={row.room} date={column.date} />
                ) : null
            }
            renderRowOverlay={(row, { labelWidth, columnWidth }) =>
                row.kind === 'row' && (
                    <>
                        {bars.blockBars
                            .filter(({ bar }) => bar.rowKey === row.key)
                            .map(({ bar, block }) => (
                                <BlockBar
                                    key={bar.id}
                                    bar={bar}
                                    block={block}
                                    room={roomByKey.get(bar.rowKey)}
                                    reason={block?.reasonLabel ?? 'Blocked'}
                                    labelWidth={labelWidth}
                                    columnWidth={columnWidth}
                                />
                            ))}
                        {bars.stayBars
                            .filter(({ bar }) => bar.rowKey === row.key)
                            .map(({ bar, stay }) =>
                                stay ? (
                                    <StayBar
                                        key={bar.id}
                                        stay={stay}
                                        room={roomByKey.get(bar.rowKey)}
                                        bar={bar}
                                        dates={dates}
                                        labelWidth={labelWidth}
                                        columnWidth={columnWidth}
                                        highlighted={
                                            hoveredReservation !== null &&
                                            stay.groupName !== null &&
                                            stay.reservationId ===
                                                hoveredReservation
                                        }
                                        onHover={setHoveredReservation}
                                        onOpen={onOpenStay}
                                    />
                                ) : null,
                            )}
                        {bars.markers
                            .filter((marker) => marker.rowKey === row.key)
                            .map((marker) => (
                                <DayUseBar
                                    key={marker.id}
                                    marker={marker}
                                    room={roomByKey.get(marker.rowKey)}
                                    labelWidth={labelWidth}
                                    columnWidth={columnWidth}
                                    canViewReservations={canViewReservations}
                                />
                            ))}
                    </>
                )
            }
            pinnedHeight={48}
            renderPinnedLabel={
                pinnedType
                    ? () => (
                          <NightlyRateLabel
                              currencyCode={currency?.code ?? null}
                              roomTypeName={pinnedType.roomTypeName}
                              planName={planName}
                          />
                      )
                    : undefined
            }
            renderPinnedCell={
                pinnedType
                    ? (column) =>
                          priceCell(
                              pinnedType.roomTypeId,
                              pinnedType.roomTypeName,
                              column.date,
                          )
                    : undefined
            }
            renderGroupCell={
                priceInGroups
                    ? (row, column) =>
                          row.kind === 'group' && row.roomTypeId !== null
                              ? priceCell(
                                    row.roomTypeId,
                                    row.title,
                                    column.date,
                                )
                              : null
                    : undefined
            }
            renderFooterLabel={() => <InventoryFooterLabel />}
            renderFooterCell={(column) => (
                <InventoryFooterCell day={inventory.get(column.date)} />
            )}
        />
    );
}
