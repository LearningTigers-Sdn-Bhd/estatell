import { useForm } from '@inertiajs/react';
import { RotateCcw, Ship } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { runs as runsRoute } from '@/routes/reports/boat-manifest';

type Run = App.Data.Boats.BoatDepartureRunData;
type Boat = App.Data.Boats.BoatData;

type RunFormItem = {
    boat_departure_id: number;
    boat_id: string;
    crew: string;
    reset_to_default?: boolean;
};

function BoatsAndCrewForm({
    runs,
    boats,
    date,
    canSet,
}: {
    runs: Run[];
    boats: Boat[];
    date: string;
    canSet: boolean;
}) {
    const boatOptions = boats.map((boat) => ({
        value: String(boat.id),
        label: boat.name,
    }));

    const form = useForm<{
        date: string;
        runs: RunFormItem[];
    }>({
        date,
        runs: runs.map((run) => ({
            boat_departure_id: run.departureId,
            boat_id:
                run.boatId === null || run.boatId === undefined
                    ? ''
                    : String(run.boatId),
            crew: run.crew ?? '',
            reset_to_default: false,
        })),
    });

    const updateRun = (
        index: number,
        field: 'boat_id' | 'crew',
        value: string,
    ) => {
        const nextRuns = [...form.data.runs];
        nextRuns[index] = {
            ...nextRuns[index],
            [field]: value,
            reset_to_default: false,
        };
        form.setData('runs', nextRuns);
    };

    const revertRow = (index: number, run: Run) => {
        const nextRuns = [...form.data.runs];
        nextRuns[index] = {
            ...nextRuns[index],
            boat_id:
                run.defaultBoatId === null || run.defaultBoatId === undefined
                    ? ''
                    : String(run.defaultBoatId),
            crew: run.defaultCrew ?? '',
            reset_to_default: true,
        };
        form.setData('runs', nextRuns);
    };

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            runs: data.runs.map((r) => ({
                ...r,
                boat_id: r.boat_id === '' ? null : Number(r.boat_id),
            })),
        }));
        form.put(runsRoute.url(), { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="pt-1">
            <div className="divide-y">
                {runs.map((run, index) => {
                    const formRun = form.data.runs[index];
                    if (!formRun) {
                        return null;
                    }

                    return (
                        <div
                            key={`${date}-${run.departureId}`}
                            className="flex flex-wrap items-center gap-2 py-2 first:pt-0"
                        >
                            <div className="flex w-52 flex-col">
                                <span className="text-sm font-medium">
                                    {run.label}
                                </span>
                                {run.isOverridden ? (
                                    <span className="text-xs font-normal text-amber-600 dark:text-amber-500">
                                        Custom override
                                    </span>
                                ) : run.defaultBoatId !== null ||
                                  run.defaultCrew ? (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        Timetable default
                                    </span>
                                ) : null}
                            </div>
                            <div className="flex flex-col gap-1">
                                <EnumSelect
                                    aria-label={`Boat for ${run.label}`}
                                    className="w-44"
                                    options={boatOptions}
                                    allLabel="No boat"
                                    value={formRun.boat_id}
                                    onValueChange={(value) =>
                                        updateRun(index, 'boat_id', value)
                                    }
                                    disabled={!canSet}
                                />
                                <FieldError>
                                    {
                                        (
                                            form.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        )[`runs.${index}.boat_id`]
                                    }
                                </FieldError>
                            </div>
                            <div className="flex flex-col gap-1">
                                <Input
                                    aria-label={`Crew for ${run.label}`}
                                    className="w-56"
                                    placeholder="Crew, e.g. Awang / Daus"
                                    maxLength={120}
                                    value={formRun.crew}
                                    onChange={(event) =>
                                        updateRun(
                                            index,
                                            'crew',
                                            event.target.value,
                                        )
                                    }
                                    disabled={!canSet}
                                />
                                <FieldError>
                                    {
                                        (
                                            form.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        )[`runs.${index}.crew`]
                                    }
                                </FieldError>
                            </div>
                            {canSet &&
                                (run.isOverridden ||
                                    formRun.reset_to_default) &&
                                (run.defaultBoatId !== null ||
                                    run.defaultCrew) && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => revertRow(index, run)}
                                        title="Revert to timetable default"
                                        className="text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        <RotateCcw
                                            data-icon="inline-start"
                                            className="size-3.5"
                                        />
                                        Default
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
 * The boat and crew of departures on the manifest date. They print in the section title of each departure, for
 * the boat crew. Staff without reservations.manage see them read only.
 */
export function BoatsAndCrewPanel({
    runs,
    boats,
    date,
    canSet,
}: {
    runs: Run[];
    boats: Boat[];
    date: string;
    canSet: boolean;
}) {
    if (runs.length === 0) {
        return null;
    }

    const stateKey = `${date}-${runs.map((r) => `${r.departureId}:${r.boatId}:${r.crew}:${r.isOverridden}`).join(',')}`;

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
                    {runs.length === 1
                        ? '1 departure'
                        : `${runs.length} departures`}
                </Badge>
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-1">
                <BoatsAndCrewForm
                    key={stateKey}
                    runs={runs}
                    boats={boats}
                    date={date}
                    canSet={canSet}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}
