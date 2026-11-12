import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/settings/concierge/amenities';

type Amenity = App.Data.Concierge.ConciergeAmenitySettingsData;

function hasDetails(amenity: Amenity): boolean {
    return !!(
        amenity.location ||
        amenity.openingHours ||
        amenity.feeInformation ||
        amenity.reservationRequired ||
        amenity.guestNotes
    );
}

/** The amenities the property offers, each with the details a guest reads: where, when, cost and reservation. */
export function AmenityDetails({ amenities }: { amenities: Amenity[] }) {
    const [editing, setEditing] = useState<Amenity | null>(null);

    return (
        <Card className="max-w-md print:hidden">
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold">Facility details</h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        Add where a facility is, when it is open, what it costs
                        and whether guests must reserve.
                    </p>
                </div>
                <ul className="flex flex-col gap-2">
                    {amenities.map((amenity) => (
                        <li
                            key={amenity.id}
                            className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                        >
                            <span className="flex flex-col gap-0.5">
                                <span className="font-medium">
                                    {amenity.name}
                                </span>
                                <span className="text-muted-foreground">
                                    {amenity.category}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                {hasDetails(amenity) && (
                                    <Badge variant="secondary">Details</Badge>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Edit details of ${amenity.name}`}
                                    onClick={() => setEditing(amenity)}
                                >
                                    <Pencil />
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
            {editing && (
                <DetailSheet
                    key={editing.id}
                    amenity={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </Card>
    );
}

function DetailSheet({
    amenity,
    onClose,
}: {
    amenity: Amenity;
    onClose: () => void;
}) {
    const form = useForm({
        location: amenity.location ?? '',
        opening_hours: amenity.openingHours ?? '',
        fee_information: amenity.feeInformation ?? '',
        reservation_required: amenity.reservationRequired,
        reservation_instructions: amenity.reservationInstructions ?? '',
        guest_notes: amenity.guestNotes ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url({ amenity: amenity.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
        });
    };

    const text = (
        name: 'location' | 'opening_hours' | 'fee_information',
        label: string,
        placeholder: string,
        maxLength: number,
    ) => (
        <Field data-invalid={!!form.errors[name]}>
            <FieldLabel htmlFor={`amenity-${name}`}>{label}</FieldLabel>
            <Input
                id={`amenity-${name}`}
                value={form.data[name]}
                maxLength={maxLength}
                placeholder={placeholder}
                onChange={(event) => form.setData(name, event.target.value)}
            />
            <FieldError>{form.errors[name]}</FieldError>
        </Field>
    );

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{amenity.name}</SheetTitle>
                    <SheetDescription>
                        What a guest reads about this facility. Leave a box
                        empty to hide it.
                    </SheetDescription>
                </SheetHeader>
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <FieldGroup className="flex-1 overflow-y-auto px-4">
                        {text(
                            'location',
                            'Where',
                            'e.g. Level 5, rooftop',
                            200,
                        )}
                        {text(
                            'opening_hours',
                            'Opening hours',
                            'e.g. Daily 7 am to 10 pm',
                            300,
                        )}
                        {text(
                            'fee_information',
                            'Cost',
                            'e.g. Free for guests',
                            300,
                        )}
                        <div className="flex items-center justify-between gap-4">
                            <Label htmlFor="amenity-reservation">
                                Guests must reserve
                            </Label>
                            <Switch
                                id="amenity-reservation"
                                checked={form.data.reservation_required}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'reservation_required',
                                        checked,
                                    )
                                }
                            />
                        </div>
                        {form.data.reservation_required && (
                            <Field
                                data-invalid={
                                    !!form.errors.reservation_instructions
                                }
                            >
                                <FieldLabel htmlFor="amenity-reservation-how">
                                    How to reserve
                                </FieldLabel>
                                <Textarea
                                    id="amenity-reservation-how"
                                    rows={3}
                                    maxLength={500}
                                    value={form.data.reservation_instructions}
                                    onChange={(event) =>
                                        form.setData(
                                            'reservation_instructions',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>
                                    {form.errors.reservation_instructions}
                                </FieldError>
                            </Field>
                        )}
                        <Field data-invalid={!!form.errors.guest_notes}>
                            <FieldLabel htmlFor="amenity-notes">
                                Notes for guests
                            </FieldLabel>
                            <Textarea
                                id="amenity-notes"
                                rows={4}
                                maxLength={1000}
                                value={form.data.guest_notes}
                                onChange={(event) =>
                                    form.setData(
                                        'guest_notes',
                                        event.target.value,
                                    )
                                }
                            />
                            <FieldError>{form.errors.guest_notes}</FieldError>
                        </Field>
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
                            Save
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
