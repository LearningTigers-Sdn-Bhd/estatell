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
            centerContent
            back={{
                href: lobby.url({ property: property.code }),
                label: 'Home',
            }}
            title="Check in"
            description="Enter the booking code from your confirmation. If you are already checked in, it opens your stay."
        >
            <div className="grid gap-8 lg:grid-cols-2 lg:gap-14">
                <Form
                    action={find.url({ property: property.code })}
                    method="post"
                    className="flex flex-col justify-center"
                >
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
                                        placeholder="Enter booking number here"
                                        className="h-14 px-4 text-base md:text-lg"
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
                                className="h-12 w-full rounded-lg"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Find my booking
                            </Button>
                        </div>
                    )}
                </Form>
                <div className="concierge-editorial-image relative h-48 overflow-hidden rounded-xl bg-muted sm:h-64 lg:h-auto lg:min-h-80 lg:rounded-none">
                    <img
                        src="/images/concierge-editorial-coast.jpg"
                        alt=""
                        width={1672}
                        height={941}
                        className="absolute inset-0 size-full object-cover"
                    />
                </div>
            </div>
        </GuestFrame>
    );
}
