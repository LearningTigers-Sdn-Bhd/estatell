import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update } from '@/routes/settings/meal-periods';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type MealPeriod = App.Data.Meals.MealPeriodData;

type Row = {
    id: number | null;
    name: string;
    starts_at: string;
    ends_at: string;
    active: boolean;
};

function toRows(mealPeriods: MealPeriod[]): Row[] {
    return mealPeriods.map((mealPeriod) => ({
        id: mealPeriod.id,
        name: mealPeriod.name,
        starts_at: mealPeriod.startsAt,
        ends_at: mealPeriod.endsAt,
        active: mealPeriod.active,
    }));
}

export function MealPeriodsForm({
    mealPeriods,
    maxPeriods,
}: {
    mealPeriods: MealPeriod[];
    maxPeriods: number;
}) {
    const form = useForm<{ meal_periods: Row[] }>({
        meal_periods: toRows(mealPeriods),
    });
    const rows = form.data.meal_periods;
    const guard = useUnsavedChanges(form.isDirty);
    const errors = form.errors as Record<string, string | undefined>;
    const canAdd = rows.length < maxPeriods;

    const setRow = (index: number, values: Partial<Row>) => {
        form.setData(
            'meal_periods',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const addRow = () => {
        form.setData('meal_periods', [
            ...rows,
            { id: null, name: '', starts_at: '', ends_at: '', active: true },
        ]);
    };

    // Saved periods are never deleted, so only a row that is not saved yet can be removed.
    const removeRow = (index: number) => {
        form.setData(
            'meal_periods',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const save = (onSaved?: () => void) => {
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <header className="space-y-1">
                <h3 className="text-base font-medium">Meal periods</h3>
                <p className="text-sm text-muted-foreground">
                    When meals are served. Package inclusions and the kitchen
                    meal count use these periods. They are listed by start time
                    after you save.
                </p>
            </header>

            {rows.length === 0 ? (
                <EmptyState
                    title="No meal periods"
                    description="Add the first time a meal is served."
                    action={
                        <Button type="button" onClick={addRow}>
                            <Plus data-icon="inline-start" />
                            Add meal period
                        </Button>
                    }
                />
            ) : (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Served</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Starts at</TableHead>
                                <TableHead>Ends at</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => (
                                <TableRow key={row.id ?? `new-${index}`}>
                                    <TableCell className="align-top">
                                        <span className="flex h-9 items-center">
                                            <Switch
                                                size="sm"
                                                checked={row.active}
                                                aria-label={`Make ${row.name || `period ${index + 1}`} ${row.active ? 'not served' : 'served'}`}
                                                onCheckedChange={(active) =>
                                                    setRow(index, { active })
                                                }
                                            />
                                        </span>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <Input
                                            aria-label={`Name of meal period ${index + 1}`}
                                            placeholder="e.g. Hi-tea"
                                            maxLength={100}
                                            value={row.name}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    name: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `meal_periods.${index}.name`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `meal_periods.${index}.name`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <Input
                                            aria-label={`Start time of meal period ${index + 1}`}
                                            type="time"
                                            className="w-32"
                                            value={row.starts_at}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    starts_at:
                                                        event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `meal_periods.${index}.starts_at`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `meal_periods.${index}.starts_at`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <Input
                                            aria-label={`End time of meal period ${index + 1}`}
                                            type="time"
                                            className="w-32"
                                            value={row.ends_at}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    ends_at: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `meal_periods.${index}.ends_at`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `meal_periods.${index}.ends_at`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <div className="flex justify-end">
                                            {row.id === null && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove ${row.name || `meal period ${index + 1}`}`}
                                                    onClick={() =>
                                                        removeRow(index)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-sm font-normal text-muted-foreground">
                                            Turn a period off to stop offering
                                            it. Saved periods are not deleted.
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={!canAdd}
                                            onClick={addRow}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add meal period
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}

            <FieldError>{errors.meal_periods}</FieldError>
            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save meal periods
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name="Meal periods"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
