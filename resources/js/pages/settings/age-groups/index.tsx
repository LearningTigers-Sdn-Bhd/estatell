import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update } from '@/routes/settings/age-groups';

type AgeGroup = App.Data.Property.PropertyAgeGroupData;

type Row = { id: number | null; label: string; max_age: string };

function toRows(ageGroups: AgeGroup[]): Row[] {
    return ageGroups.map((ageGroup) => ({
        id: ageGroup.id,
        label: ageGroup.label,
        max_age: String(ageGroup.maxAge),
    }));
}

export default function AgeGroupsIndex({
    ageGroups,
    maxAge,
}: {
    ageGroups: AgeGroup[];
    maxAge: number;
}) {
    const form = useForm<{ groups: Row[] }>({ groups: toRows(ageGroups) });
    const rows = form.data.groups;
    const errors = form.errors as Record<string, string | undefined>;

    // The server derives each "From" the same way. This only shows it.
    const fromAges = rows.reduce<number[]>((ages, row, index) => {
        ages.push(index === 0 ? 0 : (Number(rows[index - 1].max_age) || 0) + 1);

        return ages;
    }, []);
    const lastMax =
        rows.length > 0 ? Number(rows[rows.length - 1].max_age) : -1;
    const adultsFrom = rows.length > 0 ? lastMax + 1 : 0;
    const canAdd = Number.isFinite(lastMax) && lastMax < maxAge;

    const setRow = (index: number, values: Partial<Row>) => {
        form.setData(
            'groups',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const addRow = () => {
        form.setData('groups', [
            ...rows,
            { id: null, label: '', max_age: String(lastMax + 1) },
        ]);
    };

    const removeRow = (index: number) => {
        form.setData(
            'groups',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Age groups" />

            <form onSubmit={submit} className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Age groups"
                    description="Who counts as an infant or a child. Every rate plan and the booking form use these groups."
                    actions={
                        <Button
                            type="submit"
                            disabled={form.processing || !form.isDirty}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save age groups
                        </Button>
                    }
                />

                {rows.length === 0 ? (
                    <EmptyState
                        title="No age groups"
                        description="Every guest counts as an adult. Add a group to price children separately."
                        action={
                            <Button type="button" onClick={addRow}>
                                <Plus data-icon="inline-start" />
                                Add age group
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Label</TableHead>
                                    <TableHead>From</TableHead>
                                    <TableHead>To</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => (
                                    <TableRow key={row.id ?? `new-${index}`}>
                                        <TableCell className="align-top">
                                            <Input
                                                aria-label={`Label of group ${index + 1}`}
                                                placeholder="e.g. Child"
                                                maxLength={50}
                                                value={row.label}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        label: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `groups.${index}.label`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {
                                                    errors[
                                                        `groups.${index}.label`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top tabular-nums">
                                            <span className="flex h-9 items-center">
                                                {fromAges[index]}
                                            </span>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <Input
                                                aria-label={`Oldest age of group ${index + 1}`}
                                                type="number"
                                                min={fromAges[index]}
                                                max={maxAge}
                                                step={1}
                                                inputMode="numeric"
                                                className="w-24"
                                                value={row.max_age}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        max_age:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `groups.${index}.max_age`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {
                                                    errors[
                                                        `groups.${index}.max_age`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            <div className="flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove ${row.label || `group ${index + 1}`}`}
                                                    onClick={() =>
                                                        removeRow(index)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            Guests aged {adultsFrom} and over count as adults.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!canAdd}
                            onClick={addRow}
                        >
                            <Plus data-icon="inline-start" />
                            Add age group
                        </Button>
                    </div>
                )}
                <FieldError>{errors.groups}</FieldError>
            </form>
        </>
    );
}
