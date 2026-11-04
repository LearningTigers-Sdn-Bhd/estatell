import { ChevronRight, List, ListTree } from 'lucide-react';
import type { ReactNode } from 'react';
import type { AmenityView } from '@/components/pms/amenities/use-amenity-table';
import { TableCell, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

/** The Grouped or List switch of an amenity table. */
export function AmenityViewToggle({
    view,
    onChange,
}: {
    view: AmenityView;
    onChange: (view: AmenityView) => void;
}) {
    return (
        <ToggleGroup
            variant="outline"
            spacing={0}
            value={[view]}
            onValueChange={(value) =>
                value[0] && onChange(value[0] as AmenityView)
            }
            aria-label="View"
        >
            <ToggleGroupItem value="grouped">
                <ListTree data-icon="inline-start" />
                Grouped
            </ToggleGroupItem>
            <ToggleGroupItem value="list">
                <List data-icon="inline-start" />
                List
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

/** A category row that opens and closes its amenities. It stays open while a search is on. */
export function AmenityGroupRow({
    name,
    count,
    open,
    disabled,
    colSpan,
    onToggle,
}: {
    name: string;
    count: ReactNode;
    open: boolean;
    disabled: boolean;
    colSpan: number;
    onToggle: () => void;
}) {
    return (
        <TableRow className="bg-muted/50 hover:bg-muted/50">
            <TableCell colSpan={colSpan} className="p-0">
                <button
                    type="button"
                    aria-expanded={open}
                    disabled={disabled}
                    onClick={onToggle}
                    className="flex w-full items-center gap-2 p-2 text-left font-medium"
                >
                    <ChevronRight
                        aria-hidden
                        className={cn(
                            'size-4 text-muted-foreground transition-transform',
                            open && 'rotate-90',
                        )}
                    />
                    {name}
                    <span className="font-normal text-muted-foreground tabular-nums">
                        ({count})
                    </span>
                </button>
            </TableCell>
        </TableRow>
    );
}
