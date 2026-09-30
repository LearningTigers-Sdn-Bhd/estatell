import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Utensils } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { store, update } from '@/routes/settings/meal-periods';

type MealPeriod = App.Data.Meals.MealPeriodData;

type FormData = {
    name: string;
    starts_at: string;
    ends_at: string;
    active: boolean;
};

function formValues(mealPeriod: MealPeriod | null): FormData {
    if (mealPeriod === null) {
        return { name: '', starts_at: '', ends_at: '', active: true };
    }

    return {
        name: mealPeriod.name,
        starts_at: mealPeriod.startsAt,
        ends_at: mealPeriod.endsAt,
        active: mealPeriod.active,
    };
}

function MealPeriodForm({
    mealPeriod,
    onDone,
}: {
    mealPeriod: MealPeriod | null;
    onDone: () => void;
}) {
    const form = useForm<FormData>(formValues(mealPeriod));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (mealPeriod) {
            form.put(update.url({ mealPeriod: mealPeriod.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="meal-period-name">Name</FieldLabel>
                    <Input
                        id="meal-period-name"
                        placeholder="e.g. Hi-tea"
                        name="name"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.starts_at}>
                        <FieldLabel htmlFor="meal-period-starts-at">
                            Starts at
                        </FieldLabel>
                        <Input
                            id="meal-period-starts-at"
                            name="starts_at"
                            type="time"
                            value={form.data.starts_at}
                            onChange={(event) =>
                                form.setData('starts_at', event.target.value)
                            }
                            aria-invalid={!!form.errors.starts_at}
                        />
                        <FieldError>{form.errors.starts_at}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.ends_at}>
                        <FieldLabel htmlFor="meal-period-ends-at">
                            Ends at
                        </FieldLabel>
                        <Input
                            id="meal-period-ends-at"
                            name="ends_at"
                            type="time"
                            value={form.data.ends_at}
                            onChange={(event) =>
                                form.setData('ends_at', event.target.value)
                            }
                            aria-invalid={!!form.errors.ends_at}
                        />
                        <FieldError>{form.errors.ends_at}</FieldError>
                    </Field>
                </div>
                <Field orientation="horizontal">
                    <Switch
                        id="meal-period-active"
                        name="active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="meal-period-active">
                            Served
                        </FieldLabel>
                        <FieldDescription>
                            Turn it off to stop offering this meal without
                            deleting it. Periods are listed by start time.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {mealPeriod ? 'Save changes' : 'Add meal period'}
                </Button>
            </SheetFooter>
        </form>
    );
}

export default function MealPeriodsIndex({
    mealPeriods,
}: {
    mealPeriods: MealPeriod[];
}) {
    const [editing, setEditing] = useState<MealPeriod | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const toggleActive = (mealPeriod: MealPeriod, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(mealPeriod.id);
                } else {
                    next.delete(mealPeriod.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ mealPeriods: MealPeriod[] }>((props) => ({
                mealPeriods: props.mealPeriods.map((period) =>
                    period.id === mealPeriod.id
                        ? { ...period, active }
                        : period,
                ),
            }))
            .put(
                update.url({ mealPeriod: mealPeriod.id }),
                { ...formValues(mealPeriod), active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    return (
        <>
            <Head title="Meal periods" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Meal periods"
                    description="When meals are served. Periods are listed by start time."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            New meal period
                        </Button>
                    }
                />

                {mealPeriods.length === 0 ? (
                    <EmptyState
                        icon={Utensils}
                        title="No meal periods"
                        description="Add the first time a meal is served."
                        action={
                            <Button onClick={() => setEditing(null)}>
                                <Plus data-icon="inline-start" />
                                New meal period
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Served</TableHead>
                                    <TableHead>Meal period</TableHead>
                                    <TableHead>Time</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {mealPeriods.map((mealPeriod) => (
                                    <TableRow key={mealPeriod.id}>
                                        <TableCell>
                                            <Switch
                                                size="sm"
                                                checked={mealPeriod.active}
                                                disabled={togglingIds.has(
                                                    mealPeriod.id,
                                                )}
                                                aria-label={`Make ${mealPeriod.name} ${mealPeriod.active ? 'not served' : 'served'}`}
                                                onCheckedChange={(active) =>
                                                    toggleActive(
                                                        mealPeriod,
                                                        active,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <span className="font-medium">
                                                {mealPeriod.name}
                                            </span>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {mealPeriod.startsAt} –{' '}
                                            {mealPeriod.endsAt}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                {!mealPeriod.active && (
                                                    <Badge variant="secondary">
                                                        Inactive
                                                    </Badge>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(mealPeriod)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            <Sheet
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>
                            {editing
                                ? `Edit ${editing.name}`
                                : 'New meal period'}
                        </SheetTitle>
                        <SheetDescription>
                            Set the name and the time range. It cannot overlap
                            another active period.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <MealPeriodForm
                            key={editing?.id ?? 'new'}
                            mealPeriod={editing}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
