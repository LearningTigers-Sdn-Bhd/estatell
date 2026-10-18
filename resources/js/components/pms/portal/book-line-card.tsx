import { Trash2 } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PhoneInput } from '@/components/pms/phone-input';
import { resizeAges } from '@/components/pms/portal/book-lines';
import type { BookLine } from '@/components/pms/portal/book-lines';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

type RoomType = App.Data.Portal.PortalRoomTypeData;
type LineOffers = App.Data.Portal.PortalLineOffersData;
type Errors = Record<string, string | undefined>;

/**
 * One line of the book page: the room type and its guests (or the beds of a dorm), then, after a search, the offers
 * to pick from and the lead guest. A blocked offer is shown with its reason and cannot be picked.
 */
export function BookLineCard({
    index,
    line,
    roomTypes,
    result,
    errors,
    currency,
    canRemove,
    onChange,
    onRemove,
}: {
    index: number;
    line: BookLine;
    roomTypes: RoomType[];
    /** The offers of this line, or null before a search and after a change that needs a new one. */
    result: LineOffers | null;
    errors: Errors;
    currency: App.Data.Property.CurrencyData | null;
    canRemove: boolean;
    onChange: (patch: Partial<BookLine>) => void;
    onRemove: () => void;
}) {
    const roomType = roomTypes.find(
        (type) => String(type.id) === line.room_type_id,
    );
    const isBed = roomType?.sellUnit === 'bed';
    const error = (field: string) => errors[`lines.${index}.${field}`];
    const agesError = Object.entries(errors).find(([key]) =>
        key.startsWith(`lines.${index}.child_ages`),
    )?.[1];

    return (
        <section
            aria-label={`Line ${index + 1}`}
            className="flex flex-col gap-4 rounded-lg border p-4"
        >
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Line {index + 1}</h2>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Remove line ${index + 1}`}
                    disabled={!canRemove}
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-[minmax(0,1.5fr)_repeat(2,minmax(0,1fr))]">
                <Field data-invalid={!!error('room_type_id')}>
                    <FieldLabel htmlFor={`line-${index}-room-type`}>
                        Room type
                    </FieldLabel>
                    <EnumSelect
                        id={`line-${index}-room-type`}
                        placeholder="Select a room type"
                        options={roomTypes.map((type) => ({
                            value: String(type.id),
                            label: type.name,
                        }))}
                        value={line.room_type_id}
                        onValueChange={(value) =>
                            onChange({
                                room_type_id: value,
                                rate_plan_id: '',
                                child_ages: [],
                            })
                        }
                        aria-invalid={!!error('room_type_id')}
                    />
                    <FieldError>{error('room_type_id')}</FieldError>
                </Field>

                {isBed ? (
                    <Field data-invalid={!!error('beds')}>
                        <FieldLabel htmlFor={`line-${index}-beds`}>
                            Beds
                        </FieldLabel>
                        <Input
                            id={`line-${index}-beds`}
                            type="number"
                            min={1}
                            max={99}
                            placeholder="e.g. 4"
                            value={line.beds}
                            onChange={(event) =>
                                onChange({
                                    beds: event.target.value,
                                    rate_plan_id: '',
                                })
                            }
                            aria-invalid={!!error('beds')}
                        />
                        <FieldError>{error('beds')}</FieldError>
                    </Field>
                ) : (
                    <>
                        <Field data-invalid={!!error('adults')}>
                            <FieldLabel htmlFor={`line-${index}-adults`}>
                                Adults
                            </FieldLabel>
                            <Input
                                id={`line-${index}-adults`}
                                type="number"
                                min={1}
                                max={99}
                                placeholder="e.g. 2"
                                title={
                                    roomType
                                        ? `Up to ${roomType.maxAdults}`
                                        : undefined
                                }
                                value={line.adults}
                                onChange={(event) =>
                                    onChange({
                                        adults: event.target.value,
                                        rate_plan_id: '',
                                    })
                                }
                                aria-invalid={!!error('adults')}
                            />
                            <FieldError>{error('adults')}</FieldError>
                        </Field>
                        <Field>
                            <FieldLabel htmlFor={`line-${index}-children`}>
                                Children
                            </FieldLabel>
                            <Input
                                id={`line-${index}-children`}
                                type="number"
                                min={0}
                                max={20}
                                placeholder="e.g. 1"
                                title={
                                    roomType
                                        ? `Up to ${roomType.maxChildren}`
                                        : undefined
                                }
                                value={line.child_ages.length}
                                onChange={(event) =>
                                    onChange({
                                        child_ages: resizeAges(
                                            line.child_ages,
                                            Number.parseInt(
                                                event.target.value,
                                                10,
                                            ) || 0,
                                        ),
                                        rate_plan_id: '',
                                    })
                                }
                            />
                        </Field>
                    </>
                )}
            </div>

            {!isBed && line.child_ages.length > 0 && (
                <FieldSet data-invalid={!!agesError}>
                    <FieldLegend variant="label">Age of each child</FieldLegend>
                    <div className="flex flex-wrap gap-3">
                        {line.child_ages.map((age, position) => (
                            <Input
                                key={position}
                                type="number"
                                min={0}
                                max={17}
                                placeholder="e.g. 6"
                                aria-label={`Age of child ${position + 1}`}
                                className="w-24"
                                value={age}
                                onChange={(event) =>
                                    onChange({
                                        child_ages: line.child_ages.map(
                                            (current, spot) =>
                                                spot === position
                                                    ? event.target.value
                                                    : current,
                                        ),
                                        rate_plan_id: '',
                                    })
                                }
                                aria-invalid={!!agesError}
                            />
                        ))}
                    </div>
                    <FieldError>{agesError}</FieldError>
                </FieldSet>
            )}

            {result?.lineError && (
                <Alert variant="destructive">
                    <AlertDescription>{result.lineError}</AlertDescription>
                </Alert>
            )}

            {result && !result.lineError && (
                <FieldSet data-invalid={!!error('rate_plan_id')}>
                    <FieldLegend variant="label">Rate</FieldLegend>
                    {result.offers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No rate is available for this room type.
                        </p>
                    ) : (
                        <RadioGroup
                            value={line.rate_plan_id}
                            onValueChange={(value) =>
                                onChange({ rate_plan_id: String(value) })
                            }
                        >
                            {result.offers.map((offer) => {
                                const blocked = offer.reason !== null;

                                return (
                                    <FieldLabel
                                        key={offer.ratePlanId}
                                        htmlFor={`line-${index}-offer-${offer.ratePlanId}`}
                                    >
                                        <Field
                                            orientation="horizontal"
                                            className={cn(
                                                'items-start',
                                                blocked && 'opacity-70',
                                            )}
                                        >
                                            <RadioGroupItem
                                                id={`line-${index}-offer-${offer.ratePlanId}`}
                                                value={String(offer.ratePlanId)}
                                                disabled={blocked}
                                                className="mt-0.5"
                                            />
                                            <span className="flex flex-1 flex-col gap-0.5">
                                                <span className="flex justify-between gap-2 text-sm font-medium">
                                                    {offer.ratePlanName}
                                                    {offer.total !== null && (
                                                        <MoneyText
                                                            amount={offer.total}
                                                            currency={currency}
                                                        />
                                                    )}
                                                </span>
                                                {offer.reason && (
                                                    <span className="text-sm text-destructive">
                                                        {offer.reason}
                                                    </span>
                                                )}
                                            </span>
                                        </Field>
                                    </FieldLabel>
                                );
                            })}
                        </RadioGroup>
                    )}
                    <FieldError>{error('rate_plan_id')}</FieldError>
                </FieldSet>
            )}

            {result && !result.lineError && (
                <FieldGroup className="sm:grid sm:grid-cols-2">
                    <Field data-invalid={!!error('guest_name')}>
                        <FieldLabel htmlFor={`line-${index}-guest-name`}>
                            {index === 0 ? 'Guest name (booker)' : 'Guest name'}
                        </FieldLabel>
                        <Input
                            id={`line-${index}-guest-name`}
                            placeholder="e.g. Tan Wei Ming"
                            maxLength={150}
                            value={line.guest_name}
                            onChange={(event) =>
                                onChange({ guest_name: event.target.value })
                            }
                            aria-invalid={!!error('guest_name')}
                        />
                        <FieldError>{error('guest_name')}</FieldError>
                    </Field>
                    <Field data-invalid={!!error('guest_phone')}>
                        <FieldLabel htmlFor={`line-${index}-guest-phone`}>
                            Phone (optional)
                        </FieldLabel>
                        <PhoneInput
                            id={`line-${index}-guest-phone`}
                            placeholder="e.g. +60 12-345 6789"
                            value={line.guest_phone}
                            onChange={(value) =>
                                onChange({ guest_phone: value })
                            }
                            invalid={!!error('guest_phone')}
                        />
                        <FieldError>{error('guest_phone')}</FieldError>
                    </Field>
                </FieldGroup>
            )}
        </section>
    );
}
