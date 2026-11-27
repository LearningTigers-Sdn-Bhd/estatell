import { Link } from '@inertiajs/react';
import { TableCell, TableRow } from '@/components/ui/table';
import { dashboard as propertyDashboard } from '@/routes';

/** Whether the row at `index` starts a new property: the first row, or the first after another property's rows. */
export function startsProperty<Row>(
    rows: Row[],
    index: number,
    code: (row: Row) => string | null,
): boolean {
    return index === 0 || code(rows[index - 1]) !== code(rows[index]);
}

/**
 * The heading of one property's rows in a group table, spanning every column, so the property is named once instead of
 * in a column on every row. Links to the property when its code is known. The rows must come sorted by property.
 */
export function PropertySectionRow({
    name,
    code,
    colSpan,
}: {
    name: string;
    code: string | null;
    colSpan: number;
}) {
    return (
        <TableRow className="bg-muted/50 hover:bg-muted/50">
            <TableCell
                colSpan={colSpan}
                className="py-1.5 text-xs font-medium text-muted-foreground"
            >
                {code ? (
                    <Link
                        href={propertyDashboard.url({ property: code })}
                        className="underline-offset-2 hover:text-foreground hover:underline"
                    >
                        {name}
                    </Link>
                ) : (
                    name
                )}
            </TableCell>
        </TableRow>
    );
}
