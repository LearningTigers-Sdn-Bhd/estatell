import { useForm } from '@inertiajs/react';
import { Ship } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
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

function RunRow({
    run,
    date,
    boatOptions,
    canSet,
}: {
    run: Run;
    date: string;
    boatOptions: { value: string; label: string }[];
    canSet: boolean;
}) {
    const form = useForm({
        boat_departure_id: run.departureId,
        date,
        boat_id: run.boatId === null ? '' : String(run.boatId),
        crew: run.crew ?? '',
    });

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            boat_id: data.boat_id === '' ? null : Number(data.boat_id),
        }));
        form.put(runsRoute.url(), { preserveScroll: true });
    };

    return (
        <form
            onSubmit={save}
            className="flex flex-wrap items-start gap-2 border-t py-2 first:border-t-0"
        >
            <span className="w-48 pt-1.5 text-sm font-medium">{run.label}</span>
            <div className="flex flex-col gap-1">
                <EnumSelect
                    aria-label={`Boat for ${run.label}`}
                    className="w-40"
                    options={boatOptions}
                    allLabel="No boat"
                    value={form.data.boat_id}
                    onValueChange={(value) => form.setData('boat_id', value)}
                    disabled={!canSet}
                />
                <FieldError>{form.errors.boat_id}</FieldError>
            </div>
            <Input
                aria-label={`Crew for ${run.label}`}
                className="w-56"
                placeholder="Crew, e.g. Awang / Daus"
                maxLength={120}
                value={form.data.crew}
                onChange={(event) => form.setData('crew', event.target.value)}
                disabled={!canSet}
            />
            {canSet && (
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={!form.isDirty || form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save
                </Button>
            )}
        </form>
    );
}

/**
 * The boat and crew of each departure on the manifest date. They print in the section title of each departure, for
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

    const boatOptions = boats.map((boat) => ({
        value: String(boat.id),
        label: boat.name,
    }));

    return (
        <Collapsible className="rounded-lg border px-3 py-2">
            <CollapsibleTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-mx-2 justify-start"
                    />
                }
            >
                <Ship data-icon="inline-start" />
                Boats and crew
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-1">
                {runs.map((run) => (
                    <RunRow
                        key={`${date}-${run.departureId}`}
                        run={run}
                        date={date}
                        boatOptions={boatOptions}
                        canSet={canSet}
                    />
                ))}
            </CollapsibleContent>
        </Collapsible>
    );
}
