import { useForm } from '@inertiajs/react';
import { RotateCcw, Ship } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { sailings as sailingsRoute } from '@/routes/reports/boat-manifest';

type Sailing = App.Data.Boats.BoatMainSailingData;
type Boat = App.Data.Boats.BoatData;
type CrewMember = App.Data.Boats.BoatCrewMemberData;

const NO_BOAT = '';
const NO_IN_CHARGE = '';

type SailingFormItem = {
    boat_departure_id: number;
    boat_id: string;
    in_charge_id: string;
    assistant_ids: string[];
    reset_to_default: boolean;
};

function BoatsAndCrewForm({
    sailings,
    boats,
    crew,
    date,
    canSet,
}: {
    sailings: Sailing[];
    boats: Boat[];
    crew: CrewMember[];
    date: string;
    canSet: boolean;
}) {
    const form = useForm<{ date: string; sailings: SailingFormItem[] }>({
        date,
        sailings: sailings.map((sailing) => ({
            boat_departure_id: sailing.departureId,
            boat_id: sailing.boatId === null ? '' : String(sailing.boatId),
            in_charge_id:
                sailing.inChargeId === null ? '' : String(sailing.inChargeId),
            assistant_ids: sailing.assistantIds.map(String),
            reset_to_default: false,
        })),
    });

    const errors = form.errors as Record<string, string | undefined>;

    const update = (index: number, change: Partial<SailingFormItem>) => {
        const next = [...form.data.sailings];
        next[index] = { ...next[index], ...change, reset_to_default: false };
        form.setData('sailings', next);
    };

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(sailingsRoute.url(), { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="pt-1">
            <div className="divide-y">
                {sailings.map((sailing, index) => {
                    const item = form.data.sailings[index];

                    if (!item) {
                        return null;
                    }

                    const picked = new Set([
                        ...(sailing.inChargeId ? [sailing.inChargeId] : []),
                        ...sailing.assistantIds,
                    ]);
                    const offered = crew.filter(
                        (member) => member.active || picked.has(member.id),
                    );
                    const label = (member: CrewMember) =>
                        member.active
                            ? member.name
                            : `${member.name} (unavailable)`;
                    const boatOptions: EnumOption[] = boats
                        .filter(
                            (boat) => boat.active || boat.id === sailing.boatId,
                        )
                        .map((boat) => ({
                            value: String(boat.id),
                            label: boat.name,
                        }));
                    const inChargeOptions: EnumOption[] = [
                        { value: NO_IN_CHARGE, label: 'Nobody' },
                        ...offered.map((member) => ({
                            value: String(member.id),
                            label: label(member),
                        })),
                    ];
                    const assistantOptions = offered
                        .filter(
                            (member) => String(member.id) !== item.in_charge_id,
                        )
                        .map((member) => ({
                            value: String(member.id),
                            label: label(member),
                        }));

                    return (
                        <div
                            key={`${date}-${sailing.departureId}`}
                            className="flex flex-wrap items-start gap-2 py-2 first:pt-0"
                        >
                            <div className="flex w-52 flex-col">
                                <span className="text-sm font-medium">
                                    {sailing.label}
                                </span>
                                {sailing.isStored ? (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        Set for this date
                                        {sailing.otherBoats > 0
                                            ? `, ${sailing.otherBoats} more ${sailing.otherBoats === 1 ? 'boat' : 'boats'}`
                                            : ''}
                                    </span>
                                ) : (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        Timetable default
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-col gap-1">
                                <EnumSelect
                                    aria-label={`Boat for ${sailing.label}`}
                                    className="w-44"
                                    options={boatOptions}
                                    allLabel="No boat"
                                    value={item.boat_id || NO_BOAT}
                                    onValueChange={(value) =>
                                        update(index, { boat_id: value })
                                    }
                                    disabled={!canSet}
                                />
                                <FieldError>
                                    {errors[`sailings.${index}.boat_id`]}
                                </FieldError>
                            </div>
                            <div className="flex flex-col gap-1">
                                <EnumSelect
                                    aria-label={`In charge for ${sailing.label}`}
                                    className="w-44"
                                    options={inChargeOptions}
                                    placeholder="In charge"
                                    value={item.in_charge_id || NO_IN_CHARGE}
                                    onValueChange={(value) =>
                                        update(index, {
                                            in_charge_id: value,
                                            assistant_ids:
                                                item.assistant_ids.filter(
                                                    (id) => id !== value,
                                                ),
                                        })
                                    }
                                    disabled={!canSet}
                                />
                                <FieldError>
                                    {errors[`sailings.${index}.in_charge_id`]}
                                </FieldError>
                            </div>
                            <div className="w-64">
                                <MultiCombobox
                                    id={`boat-sailing-assistants-${sailing.departureId}`}
                                    name={`sailings.${index}.assistant_ids`}
                                    items={assistantOptions}
                                    value={item.assistant_ids}
                                    onValueChange={(value) =>
                                        update(index, { assistant_ids: value })
                                    }
                                    placeholder="Assistants"
                                    emptyLabel={
                                        crew.length === 0
                                            ? 'No crew yet. Add one on the Crew tab.'
                                            : 'No matching crew.'
                                    }
                                    disabled={!canSet}
                                />
                            </div>
                            {canSet && sailing.isStored && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        const next = [...form.data.sailings];
                                        next[index] = {
                                            ...next[index],
                                            reset_to_default: true,
                                        };
                                        form.setData('sailings', next);
                                    }}
                                    title="Go back to the timetable for this date"
                                    className="text-xs text-muted-foreground hover:text-foreground"
                                >
                                    <RotateCcw
                                        data-icon="inline-start"
                                        className="size-3.5"
                                    />
                                    {item.reset_to_default
                                        ? 'Default on save'
                                        : 'Default'}
                                </Button>
                            )}
                        </div>
                    );
                })}
            </div>

            {canSet && (
                <div className="mt-3 flex items-center justify-between border-t pt-3">
                    <div className="flex items-center gap-2">
                        {form.isDirty && (
                            <span className="text-xs text-muted-foreground">
                                Unsaved changes
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {form.isDirty && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => form.reset()}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            disabled={!form.isDirty || form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save changes
                        </Button>
                    </div>
                </div>
            )}
        </form>
    );
}

/**
 * The boat and crew of the main sailing of each departure on the manifest date. They print in the section title of each
 * sailing, for the boat crew. Staff without reservations.manage see them read only. The Boats board replaces this panel.
 */
export function BoatsAndCrewPanel({
    sailings,
    boats,
    crew,
    date,
    canSet,
}: {
    sailings: Sailing[];
    boats: Boat[];
    crew: CrewMember[];
    date: string;
    canSet: boolean;
}) {
    if (sailings.length === 0) {
        return null;
    }

    const stateKey = `${date}-${sailings
        .map(
            (s) =>
                `${s.departureId}:${s.boatId}:${s.inChargeId}:${s.assistantIds.join('.')}:${s.isStored}`,
        )
        .join(',')}`;

    return (
        <Collapsible className="rounded-lg border px-3 py-2">
            <CollapsibleTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-mx-2 w-full justify-between"
                    />
                }
            >
                <div className="flex items-center gap-2">
                    <Ship data-icon="inline-start" />
                    <span>Boats and crew</span>
                </div>
                <Badge variant="outline" className="text-xs font-normal">
                    {sailings.length === 1
                        ? '1 departure'
                        : `${sailings.length} departures`}
                </Badge>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-1">
                <BoatsAndCrewForm
                    key={stateKey}
                    sailings={sailings}
                    boats={boats}
                    crew={crew}
                    date={date}
                    canSet={canSet}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
