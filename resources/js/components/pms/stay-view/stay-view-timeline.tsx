import { useMemo, useState } from 'react';
import type { Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { BlockBar } from './block-bar';
import { filterStays } from './filter-stays';
import { buildRows, toggleCollapsed } from './group-rows';
import { InventoryFooterCell, InventoryFooterLabel } from './inventory-footer';
import { NightlyRateCell, NightlyRateLabel } from './nightly-rate-row';
import { pricesByDate, resolveChoice } from './nightly-rate';
import type { RateChoice } from './nightly-rate';
import { blockBarInputs, projectBars, stayBarInputs } from './project-bars';
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
    rates,
    filters,
    showRates,
    rateChoice,
    onRateChoiceChange,
    currency,
    onOpenStay,
}: {
    gridRef: Ref<DateGridHandle>;
    window: App.Data.StayView.StayViewWindowData;
    weekendDays: number[];
    rooms: App.Data.StayView.RoomRowData[];
    stays: App.Data.StayView.StayData[];
    blocks: App.Data.StayView.BlockData[];
    rates: App.Data.StayView.RatePriceRowData[];
    filters: Filters;
    showRates: boolean;
    rateChoice: RateChoice;
    onRateChoiceChange: (choice: RateChoice) => void;
    currency: App.Data.Property.CurrencyData | null;
    onOpenStay: (unitId: number) => void;
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
    const bars = useMemo(() => {
        const stayById = new Map(
            visible.stays.map((stay) => [stay.unitId, stay]),
        );
        const stayBars = projectBars(stayBarInputs(visible.stays), dates).map(
            (bar) => ({
                bar,
                stay: stayById.get(Number(bar.id.split('-')[1])),
            }),
        );
        const blockList = blockBarInputs(blocks, visible.rooms);
        const blockById = new Map(blocks.map((block) => [block.id, block]));
        const blockBars = projectBars(blockList, dates).map((bar) => ({
            bar,
            block: blockById.get(Number(bar.id.split('-')[1])),
        }));

        return { stayBars, blockBars };
    }, [visible.stays, visible.rooms, blocks, dates]);
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

    const choice = useMemo(
        () => resolveChoice(rates, rateChoice),
        [rates, rateChoice],
    );
    const prices = useMemo(() => pricesByDate(rates, choice), [rates, choice]);
    const hasRates = showRates && rates.length > 0;

    return (
        <DateGrid
            ref={gridRef}
            fill
            aria-label="Timeline View"
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
            renderCell={() => null}
            renderRowOverlay={(row, { labelWidth, columnWidth }) =>
                row.kind === 'row' && (
                    <>
                        {bars.blockBars
                            .filter(({ bar }) => bar.rowKey === row.key)
                            .map(({ bar, block }) => (
                                <BlockBar
                                    key={bar.id}
                                    bar={bar}
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
                    </>
                )
            }
            renderPinnedLabel={
                hasRates
                    ? () => (
                          <NightlyRateLabel
                              rates={rates}
                              choice={choice}
                              onChange={onRateChoiceChange}
                          />
                      )
                    : undefined
            }
            renderPinnedCell={
                hasRates
                    ? (column) => (
                          <NightlyRateCell
                              day={prices.get(column.date)}
                              currency={currency}
                          />
                      )
                    : undefined
            }
            renderFooterLabel={() => <InventoryFooterLabel />}
            renderFooterCell={(column) => (
                <InventoryFooterCell day={inventory.get(column.date)} />
            )}
        />
    );
}
