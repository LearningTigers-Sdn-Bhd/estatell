import { Form, Link } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
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
    heroUrl,
    checkOut,
    checkInHref,
}: {
    property: App.Data.Property.PropertySummaryData;
    heroUrl: string | null;
    checkOut: boolean;
    checkInHref: string | null;
}) {
    return (
        <GuestFrame
            centerContent
            back={{
                href: lobby.url({ property: property.code }),
                label: 'Home',
            }}
            title={checkOut ? 'Check out' : 'Check in'}
            description={
                checkOut
                    ? 'Enter the booking code from your confirmation to see your bill and check out.'
                    : 'Enter the booking code from your confirmation. If you are already checked in, it opens your stay.'
            }
        >
            <div className="grid gap-8 lg:grid-cols-2 lg:gap-14">
                <Form
                    action={find.url({ property: property.code })}
                    method="post"
                    className="flex flex-col justify-center"
                >
                    {({ processing, errors }) => (
                        <div className="flex flex-col gap-6">
                            {checkInHref && (
                                <Alert className="gap-y-1 p-4">
                                    <LogIn />
                                    <AlertTitle>
                                        Looks like you haven't checked in yet
                                    </AlertTitle>
                                    <AlertDescription>
                                        <p>
                                            No worries, it only takes a minute.
                                            Check in first, and check-out will
                                            be ready when it's time to leave.
                                        </p>
                                        <Link
                                            href={checkInHref}
                                            className={buttonVariants({
                                                className:
                                                    'mt-3 w-full no-underline!',
                                            })}
                                        >
                                            Check in
                                        </Link>
                                    </AlertDescription>
                                </Alert>
                            )}
                            {checkOut && (
                                <input
                                    type="hidden"
                                    name="for"
                                    value="check-out"
                                />
                            )}
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
                <div className="concierge-editorial-image relative h-48 overflow-hidden rounded-xl bg-muted sm:h-64 lg:h-auto lg:min-h-112! lg:rounded-none">
                    <img
                        src={heroUrl ?? '/images/concierge-editorial-coast.jpg'}
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
