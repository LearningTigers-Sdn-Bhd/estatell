import { Diamond } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { placesOf } from './stay-places';

type Stay = App.Data.StayView.StayData;

/**
 * The units of a group booking in the peek sheet, as radio cards under the group name. The checked card is the unit
 * the sheet shows; picking another opens it. Only units loaded for the dates on screen are listed. The list has a
 * max height and scrolls inside, with the open unit kept in view.
 */
export function GroupUnitCards({
    groupName,
    mates,
    currentUnitId,
    rooms,
    onSelect,
}: {
    groupName: string;
    mates: Stay[];
    currentUnitId: number;
    rooms: App.Data.StayView.RoomRowData[];
    onSelect: (unitId: number) => void;
}) {
    const id = useId();
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        listRef.current
            ?.querySelector('[data-slot="radio-group-item"][data-checked]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [currentUnitId]);

    return (
        <section className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3 text-sm">
                <h3
                    id={`${id}-name`}
                    className="flex min-w-0 items-center gap-1.5 font-medium"
                >
                    <Diamond
                        aria-hidden
                        className="size-3 shrink-0 fill-current text-muted-foreground"
                    />
                    <span className="truncate">{groupName}</span>
                </h3>
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {mates.length} units
                </span>
            </div>
            <div ref={listRef} className="-m-1 max-h-96 overflow-y-auto p-1">
                <RadioGroup
                    aria-labelledby={`${id}-name`}
                    value={String(currentUnitId)}
                    onValueChange={(value) => onSelect(Number(value))}
                >
                    {mates.map((mate) => (
                        <FieldLabel
                            key={mate.unitId}
                            htmlFor={`${id}-${mate.unitId}`}
                        >
                            <Field
                                orientation="horizontal"
                                className="items-start"
                            >
                                <RadioGroupItem
                                    id={`${id}-${mate.unitId}`}
                                    value={String(mate.unitId)}
                                    className="mt-0.5"
                                />
                                <FieldContent className="min-w-0">
                                    <span className="truncate text-sm font-medium">
                                        {placesOf(mate, rooms)[0]?.label ??
                                            'Not assigned yet'}
                                    </span>
                                    <span className="truncate text-sm text-muted-foreground">
                                        {mate.guestLabel ?? 'No guest named'}
                                    </span>
                                </FieldContent>
                                <StatusBadge status={mate.status} />
                            </Field>
                        </FieldLabel>
                    ))}
                </RadioGroup>
            </div>
            <p className="text-xs text-muted-foreground">
                Only units in the dates on screen are listed.
            </p>
        </section>
    );
}
