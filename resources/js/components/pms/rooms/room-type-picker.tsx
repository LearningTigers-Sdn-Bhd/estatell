import { Badge } from '@/components/ui/badge';
import {
    Field,
    FieldContent,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export type RoomTypeTile = {
    id: number;
    name: string;
    shortLabel: string;
    colorClass: string;
    soldByBed: boolean;
    rooms: number;
    beds: number;
};

/**
 * One tile per room type, built like ChoiceCards: arrow keys move between tiles and one tile is selected. A bed-sold
 * tile adds a badge and its bed total. Onboarding also passes the Beds per room props: that input shows under the
 * radio, outside the radio label, only while the tile is selected or the count has an error. Without them the
 * tiles are only a picker (Settings > Rooms, Map tab).
 */
export function RoomTypePicker({
    tiles,
    value,
    onValueChange,
    bedsPerRoom = {},
    onBedsPerRoomChange,
    bedErrors = {},
    maxBeds = 0,
    disabled,
}: {
    tiles: RoomTypeTile[];
    value: number;
    onValueChange: (id: number) => void;
    bedsPerRoom?: Record<number, string>;
    onBedsPerRoomChange?: (id: number, value: string) => void;
    bedErrors?: Record<number, string | undefined>;
    maxBeds?: number;
    disabled?: boolean;
}) {
    const footerOpen = (id: number) => id === value || !!bedErrors[id];

    return (
        <FieldSet>
            <FieldLegend className="sr-only">Room type to set</FieldLegend>
            <RadioGroup
                value={String(value)}
                onValueChange={(next) => onValueChange(Number(next))}
                disabled={disabled}
                className="flex flex-wrap items-start gap-2"
            >
                {tiles.map((tile) => (
                    <div
                        key={tile.id}
                        className="flex flex-col rounded-lg border transition-colors hover:bg-muted/50 has-data-checked:border-primary/30 has-data-checked:bg-primary/5 has-[[data-slot=radio-group-item]:focus-visible]:border-ring has-[[data-slot=radio-group-item]:focus-visible]:ring-3 has-[[data-slot=radio-group-item]:focus-visible]:ring-ring/50 max-sm:w-full"
                    >
                        <FieldLabel
                            htmlFor={`room-type-${tile.id}`}
                            className="w-full flex-1 cursor-pointer items-start gap-2.5 px-3 py-2"
                        >
                            <RadioGroupItem
                                id={`room-type-${tile.id}`}
                                value={String(tile.id)}
                                className="mt-0.5"
                            />
                            <span
                                aria-hidden
                                className={cn(
                                    'mt-0.5 size-4 shrink-0 rounded-sm',
                                    tile.colorClass,
                                )}
                            />
                            <FieldContent>
                                <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {tile.name}
                                    {tile.soldByBed && (
                                        <Badge variant="secondary">
                                            Sold by bed
                                        </Badge>
                                    )}
                                </span>
                                <span className="text-sm font-normal text-muted-foreground">
                                    {tile.shortLabel} ·{' '}
                                    {tile.rooms === 1
                                        ? '1 room'
                                        : `${tile.rooms} rooms`}
                                    {tile.soldByBed &&
                                        ` · ${tile.beds === 1 ? '1 bed' : `${tile.beds} beds`}`}
                                </span>
                            </FieldContent>
                        </FieldLabel>
                        {onBedsPerRoomChange &&
                            tile.soldByBed &&
                            footerOpen(tile.id) && (
                                <Field
                                    orientation="horizontal"
                                    className="justify-between gap-3 border-t px-3 py-1.5"
                                    data-invalid={!!bedErrors[tile.id]}
                                >
                                    <FieldLabel
                                        htmlFor={`beds-per-room-${tile.id}`}
                                        className="text-sm font-normal"
                                    >
                                        Beds per room
                                    </FieldLabel>
                                    <Input
                                        id={`beds-per-room-${tile.id}`}
                                        type="number"
                                        min={1}
                                        max={maxBeds}
                                        step={1}
                                        inputMode="numeric"
                                        className="h-7 w-16"
                                        disabled={disabled}
                                        value={bedsPerRoom[tile.id] ?? ''}
                                        onChange={(event) =>
                                            onBedsPerRoomChange(
                                                tile.id,
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!bedErrors[tile.id]}
                                    />
                                </Field>
                            )}
                        {onBedsPerRoomChange &&
                            tile.soldByBed &&
                            bedErrors[tile.id] && (
                                <FieldError className="px-3 pb-2">
                                    {bedErrors[tile.id]}
                                </FieldError>
                            )}
                    </div>
                ))}
            </RadioGroup>
        </FieldSet>
    );
}
