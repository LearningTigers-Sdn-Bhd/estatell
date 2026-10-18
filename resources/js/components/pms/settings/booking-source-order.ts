type Row = App.Data.Reservations.BookingSourceRowData;

/** Active staff rows first, then inactive staff rows, then system rows. The sort is stable, so the server order holds. */
function rank(row: Row): number {
    if (row.isSystem) {
        return 2;
    }

    return row.isActive ? 0 : 1;
}

/** The rows that match the search (name, type or OTA channel), in list order. */
export function orderBookingSources(rows: Row[], query: string): Row[] {
    const normalized = query.trim().toLocaleLowerCase();
    const matching = normalized
        ? rows.filter((row) =>
              [row.badge.name, row.typeLabel, row.otaChannelName ?? ''].some(
                  (value) => value.toLocaleLowerCase().includes(normalized),
              ),
          )
        : [...rows];

    return matching.sort((first, second) => rank(first) - rank(second));
}

/** The ids of the active staff rows in list order, the only rows that can be moved. */
export function orderableIds(rows: Row[]): number[] {
    return rows
        .filter((row) => !row.isSystem && row.isActive)
        .map((row) => row.id);
}

/** The order after `id` takes the place of `neighbourId`. Rows hidden by a search keep their relative places. */
export function moveBeside(
    ids: number[],
    id: number,
    neighbourId: number,
): number[] | null {
    const from = ids.indexOf(id);
    const to = ids.indexOf(neighbourId);

    if (from < 0 || to < 0 || from === to) {
        return null;
    }

    const next = [...ids];
    next.splice(to, 0, ...next.splice(from, 1));

    return next;
}
