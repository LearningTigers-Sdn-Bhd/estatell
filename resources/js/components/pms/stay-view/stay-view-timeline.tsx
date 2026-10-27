import { DndContext, DragOverlay } from '@dnd-kit/core';
import { useMemo, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { cellActions, takenNights } from './bookable-cell';
import { BlockBar } from './block-bar';
import { EmptyCell } from './cell-actions';
import type { BlockCell } from './cell-actions';
import { DayUseBar } from './day-use-bar';
import { rowOnNight, rowTakesDrop } from './drop-target';
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
    barBox,
    blockBarInputs,
    dayUseCellKey,
    dayUseMarkers,
    lastNightOf,
    projectBars,
    stayBarInputs,
} from './project-bars';
import { GroupLabel, LaneLabel, RoomLabel } from './room-row';
import { placeLabel } from './stay-places';
import { StayBar } from './stay-bar';
import { summarizeInventory } from './summarize-inventory';
import { TimelineDatesDialog } from './timeline-dates-dialog';
import { TimelineMoveDialog } from './timeline-move-dialog';
import { TimelineMoveStayDialog } from './timeline-move-stay-dialog';
import { unassignedLanes } from './unassigned-lane';
import { useEdgeDrag } from './use-edge-drag';
import { lockToVerticalAxis, useTimelineDrag } from './use-timeline-drag';

type Filters = App.Data.StayView.StayViewFiltersData;

const ROW_HEIGHT = 48;
const LABEL_WIDTH = 288;
const COLUMN_WIDTH = 88;

/**
 * The Timeline View grid: rooms and beds by night, with stay and block bars drawn over the cells. Filtering, grouping,
 * the bar positions, the footer counts and the rate row are all worked out here from the loaded props. A stay bar can
 * be dragged to another room or bed: rows that cannot take it are greyed out, the drop shows where the unit goes, and
 * a confirm Dialog saves it. Dragging the edge of a bar changes the arrival or the departure the same way.
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
    canOverrideRestrictions,
    canWaiveFees,
    canBlockRooms,
    onOpenStay,
    onBlockRoom,
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
    canOverrideRestrictions: boolean;
    canWaiveFees: boolean;
    canBlockRooms: boolean;
    onOpenStay: (unitId: number) => void;
    onBlockRoom: (cell: BlockCell) => void;
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
    // Lane names and order come from every room, so a room group or status filter does not rename or reorder them.
    const unassigned = useMemo(
        () => unassignedLanes(visible.stays, rooms),
        [visible.stays, rooms],
    );
    const rows = useMemo(
        () =>
            buildRows(
                visible.rooms,
                filters.groupBy,
                collapsed,
                unassigned.lanes,
            ),
        [visible.rooms, filters.groupBy, collapsed, unassigned.lanes],
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
            // A bar with no room is drawn in its sub-row of the lane. The stay keeps its own segment.
            bar: {
                ...bar,
                rowKey: unassigned.subRowOf.get(bar.id) ?? bar.rowKey,
            },
            stay: stayById.get(Number(bar.id.split('-')[1])),
        }));
        const blockList = blockBarInputs(blocks, visible.rooms);
        const blockById = new Map(blocks.map((block) => [block.id, block]));
        const blockBars = projectBars(blockList, dates).map((bar) => ({
            bar,
            block: blockById.get(Number(bar.id.split('-')[1])),
        }));

        return { stayBars, blockBars, markers };
    }, [
        visible.stays,
        visible.rooms,
        blocks,
        dayUses,
        dates,
        unassigned.subRowOf,
    ]);
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
    const timelineDrag = useTimelineDrag({
        enabled: canManageReservations,
        canOverrideRestrictions,
        businessDate: window.businessDate,
        dates,
        labelWidth: LABEL_WIDTH,
        columnWidth: COLUMN_WIDTH,
        placeName: (rowKey) => {
            const room = roomByKey.get(rowKey);

            return room ? placeLabel(room) : 'That room';
        },
    });
    const edgeDrag = useEdgeDrag({
        enabled: canManageReservations,
        canOverrideRestrictions,
        businessDate: window.businessDate,
        dates,
        labelWidth: LABEL_WIDTH,
        columnWidth: COLUMN_WIDTH,
    });
    const { drag, move } = timelineDrag;
    const { outline } = edgeDrag;
    const { stayOutline } = timelineDrag;
    // The dashed outline: the nights a moved unit takes in the new row, the new place of a stay dragged sideways, or
    // the new span of a stay whose edge is dragged.
    const dropBar = useMemo(() => {
        const shifted = drag?.shifted ?? (drag ? null : stayOutline);
        const span = shifted
            ? {
                  rowKey: shifted.rowKey,
                  firstNight: shifted.arrivalDate,
                  lastNight: lastNightOf(shifted.departureDate),
                  stayFirstNight: shifted.arrivalDate,
                  stayLastNight: lastNightOf(shifted.departureDate),
              }
            : drag?.drop
              ? {
                    rowKey: drag.drop.rowKey,
                    firstNight: drag.drop.fromNight,
                    lastNight: lastNightOf(drag.stay.departureDate),
                    stayFirstNight: drag.stay.arrivalDate,
                    stayLastNight: lastNightOf(drag.stay.departureDate),
                }
              : outline
                ? {
                      rowKey: outline.rowKey,
                      firstNight: outline.arrivalDate,
                      lastNight: lastNightOf(outline.departureDate),
                      stayFirstNight: outline.arrivalDate,
                      stayLastNight: lastNightOf(outline.departureDate),
                  }
                : null;

        return span
            ? (projectBars(
                  [{ id: 'drop', ...span, halfCells: true }],
                  dates,
              )[0] ?? null)
            : null;
    }, [drag, stayOutline, outline, dates]);
    const movedFromKey = move
        ? rowOnNight(move.stay, move.drop.fromNight)
        : null;
    const movedFrom =
        movedFromKey === null ? undefined : roomByKey.get(movedFromKey);
    const stayMove = timelineDrag.stayMove;
    const stayMovedFromKey = stayMove
        ? rowOnNight(stayMove.stay, stayMove.stay.arrivalDate)
        : null;
    const openStay = (unitId: number) =>
        timelineDrag.isClickAfterDrop() ? undefined : onOpenStay(unitId);
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
        <DndContext
            sensors={timelineDrag.sensors}
            onDragStart={(event) => {
                timelineDrag.onDragStart(event);
                edgeDrag.onDragStart(event);
            }}
            onDragEnd={() => {
                timelineDrag.onDragEnd();
                edgeDrag.onDragEnd();
            }}
            onDragCancel={() => {
                timelineDrag.onDragCancel();
                edgeDrag.onDragCancel();
            }}
        >
            <DateGrid
                ref={gridRef}
                fill
                stickyGroups
                aria-label="Timeline View"
                className="rounded-none border-x-0"
                labelWidth={LABEL_WIDTH}
                columnWidth={COLUMN_WIDTH}
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
                    ) : row.room === null ? (
                        <LaneLabel lane={row.lane} />
                    ) : (
                        <RoomLabel room={row.room} />
                    )
                }
                renderCell={(row, column) =>
                    row.kind === 'row' &&
                    row.room !== null &&
                    column.date < window.businessDate ? (
                        <span
                            aria-hidden
                            className="pointer-events-none absolute inset-0 bg-muted/50"
                        />
                    ) : row.kind === 'row' && row.room !== null ? (
                        <EmptyCell
                            room={row.room}
                            date={column.date}
                            actions={cellActions({
                                canManageReservations,
                                canBlockRooms,
                                rowKey: row.key,
                                date: column.date,
                                businessDate: window.businessDate,
                                today: window.today,
                                taken,
                            })}
                            onBlock={onBlockRoom}
                        />
                    ) : null
                }
                renderRowOverlay={(row, { labelWidth, columnWidth }) =>
                    row.kind === 'row' && (
                        <>
                            {drag?.targets &&
                                drag.shift === 0 &&
                                !rowTakesDrop(
                                    drag.stay,
                                    drag.nights,
                                    row.key,
                                    drag.targets,
                                ) && (
                                    <div
                                        aria-hidden
                                        className="absolute inset-y-0 right-0 bg-background/70"
                                        style={{ left: labelWidth }}
                                    />
                                )}
                            {dropBar?.rowKey === row.key && (
                                <div
                                    aria-hidden
                                    className="absolute inset-y-1 rounded-sm border-2 border-dashed border-ring bg-ring/10"
                                    style={barBox(
                                        dropBar,
                                        labelWidth,
                                        columnWidth,
                                    )}
                                />
                            )}
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
                                            canDrag={timelineDrag.canDrag(stay)}
                                            handles={edgeDrag.handlesFor(
                                                stay,
                                                bar,
                                            )}
                                            onHover={setHoveredReservation}
                                            onOpen={openStay}
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
                                        canViewReservations={
                                            canViewReservations
                                        }
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
            <DragOverlay
                modifiers={drag?.verticalOnly ? [lockToVerticalAxis] : []}
                dropAnimation={null}
            >
                {drag && (
                    <div className="flex size-full items-center truncate border-y border-l-4 border-ring bg-background px-2 text-xs shadow-md">
                        {drag.stay.guestLabel ?? 'No guest named'}
                    </div>
                )}
            </DragOverlay>
            <TimelineMoveDialog
                move={move}
                target={move ? roomByKey.get(move.drop.rowKey) : undefined}
                current={movedFrom}
                businessDate={window.businessDate}
                onClose={timelineDrag.closeMove}
            />
            <TimelineMoveStayDialog
                change={stayMove}
                target={stayMove ? roomByKey.get(stayMove.rowKey) : undefined}
                current={
                    stayMovedFromKey === null
                        ? undefined
                        : roomByKey.get(stayMovedFromKey)
                }
                currency={currency}
                canOverrideRestrictions={canOverrideRestrictions}
                onClose={timelineDrag.closeStayMove}
            />
            <TimelineDatesDialog
                change={edgeDrag.change}
                currency={currency}
                canWaiveFees={canWaiveFees}
                canOverrideRestrictions={canOverrideRestrictions}
                onClose={edgeDrag.closeChange}
            />
        </DndContext>
    );
}
