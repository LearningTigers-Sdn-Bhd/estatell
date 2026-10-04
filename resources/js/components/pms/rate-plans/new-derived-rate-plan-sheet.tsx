import { useForm, useHttp } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { RoomTypeCheckboxItem } from '@/components/pms/rate-plans/room-type-checkbox-item';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { preview, store } from '@/routes/settings/rate-plans/derived';

type RatePlan = App.Data.Rates.RatePlanData;
type RoomType = App.Data.Rates.NewRatePlanRoomTypeData;
type Company = App.Data.CityLedger.CompanyOptionData;
type PreviewRow = App.Data.Rates.RatePlanDerivationPreviewRowData;

type Fields = {
    name: string;
    derivation: string;
    direction: string;
    value: string;
    room_type_ids: number[];
    company_ids: number[];
};

/**
 * "Create derived plan" in a right-side Sheet: a plan that follows `parent`, with its room types and, for staff who
 * manage companies, the companies that can book it. Mount it with a `key` of the parent so each parent starts fresh.
 */
export function NewDerivedRatePlanSheet({
    parent,
    roomTypes,
    companies,
    derivations,
    currency,
    open,
    onOpenChange,
}: {
    parent: RatePlan;
    roomTypes: RoomType[];
    companies: Company[];
    derivations: { value: string; label: string }[];
    currency: App.Enums.Currency;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const parentRoomTypes = roomTypes.filter((roomType) =>
        parent.roomTypeIds.includes(roomType.id),
    );
    const form = useForm<Fields>({
        name: `${parent.name} – `,
        derivation: 'percent',
        direction: 'down',
        value: '',
        room_type_ids: parentRoomTypes.map((roomType) => roomType.id),
        company_ids: [],
    });
    const http = useHttp<
        { parent_rate_plan_id: number } & Pick<
            Fields,
            'derivation' | 'direction' | 'value'
        >,
        PreviewRow[]
    >({
        parent_rate_plan_id: parent.id,
        derivation: 'percent',
        direction: 'down',
        value: '',
    });
    const [rows, setRows] = useState<PreviewRow[]>([]);
    const data = form.data;
    const valid = data.value !== '';
    const key = JSON.stringify([data.derivation, data.direction, data.value]);
    const dataRef = useRef(data);
    dataRef.current = data;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open || !valid) {
            setRows([]);

            return;
        }

        const timer = window.setTimeout(() => {
            const { derivation, direction, value } = dataRef.current;
            http.transform(() => ({
                parent_rate_plan_id: parent.id,
                derivation,
                direction,
                value,
            }));
            http.post(preview.url({ ratePlan: parent.id }), {
                onSuccess: (response) => setRows(response),
            }).catch(() => undefined);
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, open]);

    const toggle = (id: number, checked: boolean) => {
        form.setData(
            'room_type_ids',
            checked
                ? [...data.room_type_ids, id]
                : data.room_type_ids.filter((current) => current !== id),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url({ ratePlan: parent.id }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const shownRows = rows.filter((row) =>
        data.room_type_ids.includes(row.roomTypeId),
    );

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Create derived plan</SheetTitle>
                    <SheetDescription>
                        A plan that follows {parent.name}. It has no prices of
                        its own, so a change to {parent.name} reaches it at
                        once.
                    </SheetDescription>
                </SheetHeader>
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <FieldGroup className="flex-1 overflow-y-auto px-4">
                        <Field data-invalid={!!errors.name}>
                            <FieldLabel htmlFor="derived-plan-name">
                                Name
                            </FieldLabel>
                            <Input
                                id="derived-plan-name"
                                maxLength={100}
                                value={data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={!!errors.name}
                                autoFocus
                            />
                            <FieldError>{errors.name}</FieldError>
                        </Field>
                        <div className="flex flex-wrap items-start gap-4">
                            <Field className="w-auto">
                                <FieldLabel>Direction</FieldLabel>
                                <ToggleGroup
                                    variant="outline"
                                    value={[data.direction]}
                                    onValueChange={(value) =>
                                        value[0] &&
                                        form.setData('direction', value[0])
                                    }
                                    aria-label="Direction"
                                >
                                    <ToggleGroupItem value="down">
                                        Down
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="up">
                                        Up
                                    </ToggleGroupItem>
                                </ToggleGroup>
                                <FieldError>{errors.direction}</FieldError>
                            </Field>
                            <Field
                                className="w-32"
                                data-invalid={!!errors.value}
                            >
                                <FieldLabel htmlFor="derived-plan-value">
                                    Value
                                </FieldLabel>
                                <Input
                                    id="derived-plan-value"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={data.value}
                                    onChange={(event) =>
                                        form.setData(
                                            'value',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!errors.value}
                                />
                                <FieldError>{errors.value}</FieldError>
                            </Field>
                            <Field className="w-40">
                                <FieldLabel htmlFor="derived-plan-unit">
                                    Unit
                                </FieldLabel>
                                <EnumSelect
                                    id="derived-plan-unit"
                                    name="derivation"
                                    options={derivations.map((option) => ({
                                        value: option.value,
                                        label:
                                            option.value === 'percent'
                                                ? `${option.label} (%)`
                                                : `${option.label} (${currency})`,
                                    }))}
                                    value={data.derivation}
                                    onValueChange={(value) =>
                                        form.setData('derivation', value)
                                    }
                                />
                                <FieldError>{errors.derivation}</FieldError>
                            </Field>
                        </div>
                        <section
                            aria-label="Today's prices"
                            aria-busy={http.processing}
                            className="flex flex-col gap-2 rounded-lg border p-4"
                        >
                            <h3 className="flex items-center gap-2 text-sm font-medium">
                                Today's price
                                {http.processing && <Spinner />}
                            </h3>
                            {shownRows.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {valid
                                        ? 'No room types to show.'
                                        : 'Enter a value to see prices.'}
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-1 text-sm">
                                    {shownRows.map((row) => (
                                        <li
                                            key={row.roomTypeId}
                                            className="flex items-center gap-2"
                                        >
                                            <span className="font-medium">
                                                {row.name}
                                            </span>
                                            {row.parentAmount === null ||
                                            row.derivedAmount === null ? (
                                                <span className="text-muted-foreground">
                                                    No price today on{' '}
                                                    {parent.name}
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1.5 tabular-nums">
                                                    {money.format(
                                                        Number(
                                                            row.parentAmount,
                                                        ),
                                                    )}
                                                    <ArrowRight className="size-3.5 text-muted-foreground" />
                                                    {money.format(
                                                        Number(
                                                            row.derivedAmount,
                                                        ),
                                                    )}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                        <FieldSet>
                            <FieldLegend variant="label">
                                Room types
                            </FieldLegend>
                            <FieldDescription>
                                Only room types that {parent.name} sells.
                            </FieldDescription>
                            {parentRoomTypes.map((roomType) => (
                                <RoomTypeCheckboxItem
                                    key={roomType.id}
                                    id={`derived-plan-room-type-${roomType.id}`}
                                    name={roomType.name}
                                    sellUnitLabel={roomType.sellUnitLabel}
                                    fits
                                    pricingLabel={parent.pricingLabel}
                                    checked={data.room_type_ids.includes(
                                        roomType.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        toggle(roomType.id, checked)
                                    }
                                />
                            ))}
                            <FieldError>{errors.room_type_ids}</FieldError>
                        </FieldSet>
                        {companies.length > 0 && (
                            <Field data-invalid={!!errors.company_ids}>
                                <FieldLabel htmlFor="derived-plan-companies">
                                    Companies
                                </FieldLabel>
                                <MultiCombobox
                                    id="derived-plan-companies"
                                    name="company_ids"
                                    items={companies.map((company) => ({
                                        value: String(company.id),
                                        label: company.name,
                                    }))}
                                    value={data.company_ids.map(String)}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'company_ids',
                                            value.map(Number),
                                        )
                                    }
                                    placeholder="Search companies"
                                    emptyLabel="No matching companies."
                                    invalid={!!errors.company_ids}
                                />
                                <FieldDescription>
                                    Optional. Only these companies can book the
                                    plan. You can change this later on the
                                    company's Rates tab.
                                </FieldDescription>
                                <FieldError>{errors.company_ids}</FieldError>
                            </Field>
                        )}
                    </FieldGroup>
                    <SheetFooter>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Create plan
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
