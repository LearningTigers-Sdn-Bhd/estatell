import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { destroy, preview, update } from '@/routes/reports/layout';

type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The report slug, for example `arrivals`. */
    report: string;
    /** The report name, for the title. */
    reportLabel: string;
    /** The saved layout. */
    saved: Layout;
    /** The draft the page shows in its table. The sheet edits it. */
    draft: Layout;
    onDraftChange: (draft: Layout) => void;
    orientations: EnumOption[];
    /** The date fields of the report page: `from` and `to`, or `date` for a report of one date. */
    dates: Record<string, string>;
    /** Called after Save or Reset worked, to close the sheet without the discard question. */
    onDone: () => void;
};

const PREVIEW_FORM_ID = 'report-layout-preview';

function sameLayout(a: Layout, b: Layout): boolean {
    return (
        a.orientation === b.orientation &&
        a.columns.length === b.columns.length &&
        a.columns.every(
            (column, index) =>
                column.key === b.columns[index].key &&
                column.visible === b.columns[index].visible,
        )
    );
}

/**
 * The column layout of a report: orientation, the order and visibility of every column, a PDF preview and reset. The
 * page owns the draft, so its table follows each change. See docs/22-reports/01-core-reports.md.
 */
export function ReportLayoutSheet({
    open,
    onOpenChange,
    report,
    reportLabel,
    saved,
    draft,
    onDraftChange,
    orientations,
    dates,
    onDone,
}: Props) {
    const { csrfToken } = usePage().props;
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Partial<Record<string, string>>>({});
    const [confirmingReset, setConfirmingReset] = useState(false);
    const [resetting, setResetting] = useState(false);

    const visibleCount = draft.columns.filter(
        (column) => column.visible,
    ).length;
    const dirty = !sameLayout(draft, saved);

    const move = (index: number, to: number) => {
        const columns = [...draft.columns];
        const [moved] = columns.splice(index, 1);

        columns.splice(to, 0, moved);
        onDraftChange({ ...draft, columns });
    };

    const toggle = (index: number, visible: boolean) => {
        onDraftChange({
            ...draft,
            columns: draft.columns.map((column, position) =>
                position === index ? { ...column, visible } : column,
            ),
        });
    };

    const save = () => {
        router.put(
            update.url({ report }),
            {
                orientation: draft.orientation,
                columns: draft.columns.map(({ key, visible }) => ({
                    key,
                    visible,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onError: (next) => setErrors(next),
                onSuccess: onDone,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const reset = () => {
        router.delete(destroy.url({ report }), {
            preserveScroll: true,
            onStart: () => setResetting(true),
            onSuccess: () => {
                setConfirmingReset(false);
                onDone();
            },
            onFinish: () => setResetting(false),
        });
    };

    return (
        <>
            <FormOverlay
                variant="sheet"
                open={open}
                onOpenChange={onOpenChange}
                title={`Layout · ${reportLabel}`}
                description="Choose the paper orientation and the columns, in the order they show. The table behind this panel shows your changes."
                errors={errors}
                dirty={dirty}
                discardTitle="Discard layout changes?"
                onSubmit={save}
                submitLabel="Save"
                processing={processing}
                footer={
                    <div className="flex w-full flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfirmingReset(true)}
                            >
                                <RotateCcw data-icon="inline-start" />
                                Reset to default
                            </Button>
                            <Button
                                type="submit"
                                form={PREVIEW_FORM_ID}
                                variant="outline"
                            >
                                Preview PDF
                                <ExternalLink data-icon="inline-end" />
                            </Button>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Save
                            </Button>
                        </div>
                    </div>
                }
            >
                <FieldGroup>
                    <FieldSet>
                        <FieldLegend variant="label">Orientation</FieldLegend>
                        <RadioGroup
                            value={draft.orientation}
                            onValueChange={(orientation) =>
                                onDraftChange({
                                    ...draft,
                                    orientation:
                                        orientation as Layout['orientation'],
                                })
                            }
                            className="flex flex-wrap gap-4"
                        >
                            {orientations.map((option) => (
                                <Field
                                    key={option.value}
                                    orientation="horizontal"
                                    className="w-auto"
                                >
                                    <RadioGroupItem
                                        id={`orientation-${option.value}`}
                                        value={option.value}
                                    />
                                    <FieldLabel
                                        htmlFor={`orientation-${option.value}`}
                                    >
                                        {option.label}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </RadioGroup>
                    </FieldSet>

                    <FieldSet>
                        <FieldLegend variant="label">Columns</FieldLegend>
                        <ul className="flex flex-col divide-y rounded-md border">
                            {draft.columns.map((column, index) => (
                                <li
                                    key={column.key}
                                    className="flex items-center gap-3 px-3 py-2"
                                >
                                    <Switch
                                        size="sm"
                                        id={`column-${column.key}`}
                                        checked={column.visible}
                                        disabled={
                                            column.visible && visibleCount === 1
                                        }
                                        onCheckedChange={(checked) =>
                                            toggle(index, checked)
                                        }
                                    />
                                    <FieldContent className="min-w-0 flex-1">
                                        <FieldLabel
                                            htmlFor={`column-${column.key}`}
                                            className="truncate"
                                        >
                                            {column.label}
                                        </FieldLabel>
                                    </FieldContent>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Move ${column.label} up`}
                                        disabled={index === 0}
                                        onClick={() => move(index, index - 1)}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Move ${column.label} down`}
                                        disabled={
                                            index === draft.columns.length - 1
                                        }
                                        onClick={() => move(index, index + 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </FieldSet>
                </FieldGroup>
            </FormOverlay>

            <form
                id={PREVIEW_FORM_ID}
                method="post"
                action={preview.url({ report })}
                target="_blank"
                hidden
            >
                <input type="hidden" name="_token" value={csrfToken} />
                {Object.entries(dates).map(([name, value]) => (
                    <input key={name} type="hidden" name={name} value={value} />
                ))}
                <input
                    type="hidden"
                    name="orientation"
                    value={draft.orientation}
                />
                {draft.columns.map((column, index) => (
                    <span key={column.key}>
                        <input
                            type="hidden"
                            name={`columns[${index}][key]`}
                            value={column.key}
                        />
                        <input
                            type="hidden"
                            name={`columns[${index}][visible]`}
                            value={column.visible ? '1' : '0'}
                        />
                    </span>
                ))}
            </form>

            <AlertDialog
                open={confirmingReset}
                onOpenChange={setConfirmingReset}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Reset the {reportLabel} layout?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The saved orientation and column order are deleted
                            for everyone. The report goes back to its default
                            layout.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={resetting}
                            focusableWhenDisabled
                            onClick={reset}
                        >
                            {resetting && <Spinner data-icon="inline-start" />}
                            Reset layout
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
