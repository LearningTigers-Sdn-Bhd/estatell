import { Form } from '@inertiajs/react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { find, lobby } from '@/routes/concierge';

/** The booking code box: a guest with a booking opens their stay here, to check in or to use the stay pages. */
export default function ConciergeFind({
    property,
}: {
    property: App.Data.Property.PropertySummaryData;
}) {
    return (
        <GuestFrame
            back={{
                href: lobby.url({ property: property.code }),
                label: 'Home',
            }}
            title="Check in"
            description="Enter the booking code from your confirmation. If you are already checked in, it opens your stay."
        >
            <Form action={find.url({ property: property.code })} method="post">
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-6">
                        <FieldGroup>
                            <Field data-invalid={!!errors.reference}>
                                <FieldLabel htmlFor="reference">
                                    Booking code
                                </FieldLabel>
                                <Input
                                    id="reference"
                                    name="reference"
                                    autoComplete="off"
                                    autoCapitalize="characters"
                                    autoFocus
                                    required
                                    aria-invalid={!!errors.reference}
                                />
                                <FieldError>{errors.reference}</FieldError>
                            </Field>
                        </FieldGroup>

                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full rounded-full"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Find my booking
                        </Button>
                    </div>
                )}
            </Form>
        </GuestFrame>
    );
}
