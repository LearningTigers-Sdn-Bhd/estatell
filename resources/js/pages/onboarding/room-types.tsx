import { Head, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import RoomTypeController from '@/actions/App/Http/Controllers/Onboarding/RoomTypeController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type RoomType = App.Data.Rooms.RoomTypeData;
type Row = {
    id: number | null;
    name: string;
    max_adults: string;
    max_children: string;
};

const FORM_ID = 'room-types-form';

const newRow = (): Row => ({
    id: null,
    name: '',
    max_adults: '2',
    max_children: '0',
});

export default function RoomTypes({
    roomTypes,
    maxRows,
    showDormHint,
    locked,
    blockedBy,
}: {
    roomTypes: RoomType[];
    maxRows: number;
    showDormHint: boolean;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const soldByRoom = roomTypes.filter(
        (roomType) => roomType.sellUnit === 'room',
    );
    const soldByBed = roomTypes.filter(
        (roomType) => roomType.sellUnit === 'bed',
    );
    const form = useForm<{ rows: Row[] }>({
        rows:
            soldByRoom.length > 0
                ? soldByRoom.map((roomType) => ({
                      id: roomType.id,
                      name: roomType.name,
                      max_adults: String(roomType.maxAdults),
                      max_children: String(roomType.maxChildren),
                  }))
                : [newRow()],
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'room_types',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '5xl',
    });

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const removeRow = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const save = (onSuccess?: () => void) =>
        form.put(RoomTypeController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });

    const onlyRow = rows.length === 1;

    return (
        <>
            <Head title="Room types" />
            <Heading
                title="Room types"
                description="What kinds of rooms do you sell?"
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Max adults</TableHead>
                                <TableHead>Max children</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => (
                                <TableRow
                                    key={row.id ?? `new-${index}`}
                                    className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3"
                                >
                                    <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Name
                                        </span>
                                        <Input
                                            id={`rows.${index}.name`}
                                            aria-label={`Name of room type ${index + 1}`}
                                            placeholder="e.g. Standard Double"
                                            maxLength={100}
                                            value={row.name}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    name: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[`rows.${index}.name`]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`rows.${index}.name`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Max adults
                                        </span>
                                        <Input
                                            aria-label={`Max adults of room type ${index + 1}`}
                                            type="number"
                                            min={1}
                                            max={50}
                                            step={1}
                                            inputMode="numeric"
                                            placeholder="e.g. 2"
                                            className="w-24"
                                            value={row.max_adults}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    max_adults:
                                                        event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `rows.${index}.max_adults`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`rows.${index}.max_adults`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Max children
                                        </span>
                                        <Input
                                            aria-label={`Max children of room type ${index + 1}`}
                                            type="number"
                                            min={0}
                                            max={50}
                                            step={1}
                                            inputMode="numeric"
                                            placeholder="e.g. 1"
                                            className="w-24"
                                            value={row.max_children}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    max_children:
                                                        event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `rows.${index}.max_children`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {
                                                errors[
                                                    `rows.${index}.max_children`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                        <div className="flex justify-end">
                                            <Tooltip>
                                                <TooltipTrigger
                                                    render={
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Remove ${row.name || `room type ${index + 1}`}`}
                                                            disabled={onlyRow}
                                                            focusableWhenDisabled
                                                            onClick={() =>
                                                                removeRow(index)
                                                            }
                                                        />
                                                    }
                                                >
                                                    <Trash2 />
                                                </TooltipTrigger>
                                                {onlyRow && (
                                                    <TooltipContent>
                                                        At least one room type
                                                    </TooltipContent>
                                                )}
                                            </Tooltip>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4}>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={rows.length >= maxRows}
                                            focusableWhenDisabled
                                            onClick={() =>
                                                form.setData('rows', [
                                                    ...rows,
                                                    newRow(),
                                                ])
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add room type
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {soldByBed.length > 0 && (
                    <section
                        className="flex flex-col gap-2"
                        aria-labelledby="sold-by-bed"
                    >
                        <h3 id="sold-by-bed" className="text-base font-medium">
                            Sold by bed
                        </h3>
                        <ul className="divide-y rounded-lg border text-sm">
                            {soldByBed.map((roomType) => (
                                <li
                                    key={roomType.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                >
                                    <span>{roomType.name}</span>
                                    <span className="text-muted-foreground">
                                        Sold by bed. Edit in settings.
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                    <p>
                        After you go live, you can add descriptions and room
                        rules in Settings, under Room types.
                    </p>
                    {showDormHint && (
                        <p>
                            Selling dorm beds? After you go live, you can add
                            them in Settings, under Room types.
                        </p>
                    )}
                </div>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Room types"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
