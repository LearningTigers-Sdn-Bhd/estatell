import { ArrowDown, ArrowUp, RotateCcw } from 'lucide-react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { FieldContent, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import {
    DEFAULT_SCREEN_COLUMNS,
    SCREEN_COLUMNS,
} from '@/components/pms/housekeeping/use-screen-columns';
import type { ScreenColumn } from '@/components/pms/housekeeping/use-screen-columns';

/**
 * The viewer's own board columns: which show and in what order. Each change applies at once and is remembered in this
 * browser. Print and export columns have their own sheet.
 */
export function ScreenColumnsSheet({
    open,
    onOpenChange,
    columns,
    onChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    columns: ScreenColumn[];
    onChange: (columns: ScreenColumn[]) => void;
}) {
    const labelOf = (key: ScreenColumn['key']) =>
        SCREEN_COLUMNS.find((column) => column.key === key);

    const move = (index: number, to: number) => {
        const next = [...columns];
        const [moved] = next.splice(index, 1);

        next.splice(to, 0, moved);
        onChange(next);
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Screen columns"
            description="The columns you see on this board, in this browser. Printing and exports use the print columns."
            errors={{}}
            onSubmit={() => onOpenChange(false)}
            submitLabel="Done"
            processing={false}
            footerStart={
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => onChange(DEFAULT_SCREEN_COLUMNS)}
                >
                    <RotateCcw data-icon="inline-start" />
                    Reset to default
                </Button>
            }
        >
            <ul className="flex flex-col divide-y rounded-md border">
                {columns.map((column, index) => {
                    const meta = labelOf(column.key);

                    return (
                        <li
                            key={column.key}
                            className="flex items-center gap-3 px-3 py-2"
                        >
                            <Switch
                                size="sm"
                                id={`screen-column-${column.key}`}
                                checked={column.visible}
                                disabled={meta?.locked}
                                onCheckedChange={(visible) =>
                                    onChange(
                                        columns.map((item) =>
                                            item.key === column.key
                                                ? { ...item, visible }
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <FieldContent className="min-w-0 flex-1">
                                <FieldLabel
                                    htmlFor={`screen-column-${column.key}`}
                                    className="truncate"
                                >
                                    {meta?.label}
                                </FieldLabel>
                            </FieldContent>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Move ${meta?.label} up`}
                                disabled={index === 0}
                                onClick={() => move(index, index - 1)}
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Move ${meta?.label} down`}
                                disabled={index === columns.length - 1}
                                onClick={() => move(index, index + 1)}
                            >
                                <ArrowDown />
                            </Button>
                        </li>
                    );
                })}
            </ul>
        </FormOverlay>
    );
}
