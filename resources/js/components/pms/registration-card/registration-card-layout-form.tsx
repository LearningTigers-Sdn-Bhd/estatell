import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { RegistrationCardPreview } from '@/components/pms/registration-card/registration-card-preview';
import { Button } from '@/components/ui/button';
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
import { update } from '@/routes/settings/registration-card/fields';

type Field = App.Data.Registration.RegistrationCardFieldData;

const toVisibilities = (fields: Field[]) =>
    Object.fromEntries(fields.map((field) => [field.field, field.visibility]));

export function RegistrationCardLayoutForm({
    fields,
    propertyName,
}: {
    fields: Field[];
    propertyName: string;
}) {
    const [visibilities, setVisibilities] = useState(() =>
        toVisibilities(fields),
    );
    const [order, setOrder] = useState(() =>
        fields.map((field) => field.field),
    );
    const [saving, setSaving] = useState(false);
    // Bumped when the last save finishes, so the effect below re-reads the server copy.
    const [settled, setSettled] = useState(0);
    // Counts edits. A save is current only if no edit came after it was sent.
    const edits = useRef(0);
    const saved = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    const byName = new Map(fields.map((field) => [field.field, field]));
    const ordered = order.flatMap((name) => byName.get(name) ?? []);

    // The server copy is the truth once every edit is saved, so a refused change snaps back.
    useEffect(() => {
        if (edits.current !== saved.current) {
            return;
        }

        setVisibilities(toVisibilities(fields));
        setOrder(fields.map((field) => field.field));
    }, [fields, settled]);

    useEffect(() => () => clearTimeout(timer.current), []);

    // Saves 300 ms after the last change, so a burst of clicks is one request.
    const queueSave = (
        nextVisibilities: Record<string, string>,
        nextOrder: string[],
    ) => {
        const edit = ++edits.current;

        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            setSaving(true);
            router.put(
                update.url(),
                { fields: nextVisibilities, order: nextOrder },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => {
                        saved.current = Math.max(saved.current, edit);
                        setSaving(edits.current !== saved.current);
                        setSettled((count) => count + 1);
                    },
                },
            );
        }, 300);
    };

    const setVisibility = (field: string, visibility: string) => {
        const next = { ...visibilities, [field]: visibility };

        setVisibilities(next);
        queueSave(next, order);
    };

    // A field moves only inside its group, so the sections keep their order.
    const move = (index: number, step: -1 | 1) => {
        const next = [...order];
        [next[index], next[index + step]] = [next[index + step], next[index]];

        setOrder(next);
        queueSave(visibilities, next);
    };
    const canMove = (index: number, step: -1 | 1) =>
        ordered[index + step]?.group === ordered[index].group;

    const requiredCell = (field: Field) => {
        const visibility = visibilities[field.field];

        if (field.displayOnly || visibility === 'hidden') {
            return <span className="text-muted-foreground">–</span>;
        }

        if (!field.canRequire) {
            return (
                <span className="text-muted-foreground">
                    {visibility === 'required' ? 'Always' : 'Never'}
                </span>
            );
        }

        return (
            <Switch
                size="sm"
                checked={visibility === 'required'}
                aria-label={`Require ${field.label}`}
                onCheckedChange={(required) =>
                    setVisibility(
                        field.field,
                        required ? 'required' : 'optional',
                    )
                }
            />
        );
    };

    return (
        <div className="flex flex-col gap-5">
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <h3 className="text-base font-medium">Layout</h3>
                    <p className="text-sm text-muted-foreground">
                        Choose what the registration card shows and which guest
                        details are required.
                    </p>
                </div>
                <p
                    role="status"
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    {saving ? (
                        <>
                            <Spinner />
                            Saving…
                        </>
                    ) : (
                        'Changes save automatically.'
                    )}
                </p>
            </header>

            <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
                <div className="overflow-auto rounded-lg border lg:col-span-2">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-28">
                                    Visibility
                                </TableHead>
                                <TableHead>Field</TableHead>
                                <TableHead className="w-28">Required</TableHead>
                                <TableHead className="w-24 text-right">
                                    Order
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ordered.map((field, index) => (
                                <Fragment key={field.field}>
                                    {(index === 0 ||
                                        ordered[index - 1].group !==
                                            field.group) && (
                                        <TableRow className="bg-muted/50 hover:bg-muted/50">
                                            <TableCell
                                                colSpan={4}
                                                className="text-xs font-medium text-muted-foreground"
                                            >
                                                {field.groupLabel}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    <TableRow>
                                        <TableCell>
                                            <Switch
                                                size="sm"
                                                checked={
                                                    visibilities[
                                                        field.field
                                                    ] !== 'hidden'
                                                }
                                                disabled={!field.canHide}
                                                aria-label={`Show ${field.label} on the card`}
                                                onCheckedChange={(visible) =>
                                                    setVisibility(
                                                        field.field,
                                                        !visible
                                                            ? 'hidden'
                                                            : field.displayOnly
                                                              ? 'required'
                                                              : 'optional',
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                <span className="font-medium">
                                                    {field.label}
                                                </span>
                                                {field.note && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {field.note}
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {requiredCell(field)}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${field.label} up`}
                                                    disabled={
                                                        !canMove(index, -1)
                                                    }
                                                    onClick={() =>
                                                        move(index, -1)
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${field.label} down`}
                                                    disabled={
                                                        !canMove(index, 1)
                                                    }
                                                    onClick={() =>
                                                        move(index, 1)
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <RegistrationCardPreview
                    fields={ordered}
                    visibilities={visibilities}
                    propertyName={propertyName}
                />
            </div>
        </div>
    );
}
