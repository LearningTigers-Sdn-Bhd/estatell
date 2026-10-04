import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { roomTypeOption } from '@/components/pms/rate-plans/room-type-options';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/settings/rate-plans';

type Option = { value: string; label: string };
type RoomType = App.Data.Rates.NewRatePlanRoomTypeData;

const basisDescriptions: Record<string, string> = {
    per_room:
        'Sells rooms. One price for the room, for the guests it includes.',
    per_guest:
        'Sells rooms. A price for one adult. Extra adults and children add to it.',
    per_bed: 'Sells dorm beds. One price for each bed.',
    package:
        'Sells rooms or beds. One price for the whole stay, set by arrival date.',
};

/**
 * Create form in a right-side Sheet. Only the name and pricing basis are stored.
 * The editor opens on its Room types tab after saving.
 */
export function NewRatePlanSheet({
    pricingBases,
    packageUnits,
    roomTypes,
}: {
    pricingBases: Option[];
    packageUnits: Option[];
    roomTypes: RoomType[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        name: string;
        pricing_basis: string;
        fixed_nights: string;
        package_unit: string;
        room_type_ids: number[];
    }>({
        name: '',
        pricing_basis: 'per_room',
        fixed_nights: '2',
        package_unit: 'per_guest',
        room_type_ids: [],
    });
    const basis = form.data.pricing_basis as App.Enums.PricingBasis;

    // Switching the basis unticks room types the new basis cannot sell.
    const chooseBasis = (value: string) => {
        form.setData((data) => ({
            ...data,
            pricing_basis: value,
            room_type_ids: data.room_type_ids.filter((id) =>
                roomTypes
                    .find((roomType) => roomType.id === id)
                    ?.fitsBases.includes(value as App.Enums.PricingBasis),
            ),
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <>
            <Button onClick={() => setOpen(true)}>
                <Plus data-icon="inline-start" />
                New rate plan
            </Button>
            <Sheet
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);

                    if (!next) {
                        form.reset();
                        form.clearErrors();
                    }
                }}
            >
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>New rate plan</SheetTitle>
                        <SheetDescription>
                            Choose how the plan is priced and which room types
                            it sells.
                        </SheetDescription>
                    </SheetHeader>
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <FieldGroup className="flex-1 overflow-y-auto px-4">
                            <Field data-invalid={!!form.errors.name}>
                                <FieldLabel htmlFor="rate-plan-name">
                                    Name
                                </FieldLabel>
                                <Input
                                    id="rate-plan-name"
                                    placeholder="e.g. Breakfast"
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
                            <FieldSet>
                                <FieldLegend variant="label">
                                    Priced
                                </FieldLegend>
                                <RadioGroup
                                    value={form.data.pricing_basis}
                                    onValueChange={chooseBasis}
                                >
                                    {pricingBases.map((option) => (
                                        <FieldLabel
                                            key={option.value}
                                            htmlFor={`rate-plan-basis-${option.value}`}
                                        >
                                            <Field orientation="horizontal">
                                                <RadioGroupItem
                                                    id={`rate-plan-basis-${option.value}`}
                                                    value={option.value}
                                                />
                                                <FieldContent>
                                                    <span className="text-sm font-medium">
                                                        {option.label}
                                                    </span>
                                                    <FieldDescription>
                                                        {
                                                            basisDescriptions[
                                                                option.value
                                                            ]
                                                        }
                                                    </FieldDescription>
                                                </FieldContent>
                                            </Field>
                                        </FieldLabel>
                                    ))}
                                </RadioGroup>
                                <FieldError>
                                    {form.errors.pricing_basis}
                                </FieldError>
                            </FieldSet>
                            {basis === 'package' && (
                                <>
                                    <Field
                                        data-invalid={
                                            !!form.errors.fixed_nights
                                        }
                                    >
                                        <FieldLabel htmlFor="rate-plan-nights">
                                            Nights
                                        </FieldLabel>
                                        <Input
                                            id="rate-plan-nights"
                                            type="number"
                                            min="1"
                                            max="30"
                                            inputMode="numeric"
                                            className="sm:max-w-32"
                                            value={form.data.fixed_nights}
                                            onChange={(event) =>
                                                form.setData(
                                                    'fixed_nights',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={
                                                !!form.errors.fixed_nights
                                            }
                                        />
                                        <FieldDescription>
                                            The stay is fixed. 3D2N is 2 nights.
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.fixed_nights}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={
                                            !!form.errors.package_unit
                                        }
                                    >
                                        <FieldLabel htmlFor="rate-plan-unit">
                                            Charged
                                        </FieldLabel>
                                        <EnumSelect
                                            id="rate-plan-unit"
                                            name="package_unit"
                                            className="sm:max-w-60"
                                            options={packageUnits}
                                            value={form.data.package_unit}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'package_unit',
                                                    value,
                                                )
                                            }
                                            aria-invalid={
                                                !!form.errors.package_unit
                                            }
                                        />
                                        <FieldDescription>
                                            Per guest prices each adult and uses
                                            child prices. Per room prices the
                                            room and adds extra guests.
                                        </FieldDescription>
                                        <FieldError>
                                            {form.errors.package_unit}
                                        </FieldError>
                                    </Field>
                                </>
                            )}
                            {roomTypes.length > 0 && (
                                <Field
                                    data-invalid={!!form.errors.room_type_ids}
                                >
                                    <FieldLabel htmlFor="new-rate-plan-room-types">
                                        Room types
                                    </FieldLabel>
                                    <MultiCombobox
                                        id="new-rate-plan-room-types"
                                        name="room_type_ids"
                                        items={roomTypes.map((roomType) =>
                                            roomTypeOption(
                                                roomType,
                                                roomType.fitsBases.includes(
                                                    basis,
                                                ),
                                                pricingBases.find(
                                                    (option) =>
                                                        option.value === basis,
                                                )?.label ?? '',
                                            ),
                                        )}
                                        value={form.data.room_type_ids.map(
                                            String,
                                        )}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'room_type_ids',
                                                value.map(Number),
                                            )
                                        }
                                        placeholder="Search room types"
                                        emptyLabel="No matching room types."
                                        invalid={!!form.errors.room_type_ids}
                                    />
                                    <FieldDescription>
                                        Optional. You can change these later.
                                    </FieldDescription>
                                    <FieldError>
                                        {form.errors.room_type_ids}
                                    </FieldError>
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
                                Create rate plan
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>
        </>
    );
}
