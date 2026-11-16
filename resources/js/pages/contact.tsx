import { Form, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, LockKeyhole } from 'lucide-react';
import InputError from '@/components/input-error';
import { Navbar } from '@/components/landing/navbar';
import { PageMeta } from '@/components/landing/seo';
import { Container } from '@/components/landing/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { contact, home, privacy, terms } from '@/routes';
import { store } from '@/routes/contact';

type Props = {
    sent: boolean;
};

const description =
    'Share a few details and what you need. The Estatell team will get back to you.';

const fields = [
    {
        name: 'hotel_name',
        label: 'Hotel name',
        autoComplete: 'organization',
        placeholder: 'e.g. The Riverside Hotel',
    },
    {
        name: 'location',
        label: 'Location / address',
        autoComplete: 'street-address',
        placeholder: 'e.g. Kuching, Sarawak',
    },
    {
        name: 'name',
        label: 'Your name',
        autoComplete: 'name',
        placeholder: 'e.g. Alex Tan',
    },
    {
        name: 'email',
        label: 'Email address',
        type: 'email',
        autoComplete: 'email',
        placeholder: 'you@yourhotel.com',
    },
    {
        name: 'phone',
        label: 'Mobile number',
        type: 'tel',
        autoComplete: 'tel',
        placeholder: 'e.g. +60 12 345 6789',
    },
    {
        name: 'room_count',
        label: 'Number of rooms',
        type: 'number',
        min: 1,
        max: 9999,
        inputMode: 'numeric' as const,
        placeholder: 'e.g. 24',
    },
] as const;

export default function Contact({ sent }: Props) {
    return (
        <>
            <PageMeta
                title="Contact Us"
                description={description}
                path="/contact"
            />
            <Navbar />
            <main
                id="top"
                tabIndex={-1}
                className="bg-muted/40 text-foreground lg:flex lg:min-h-svh lg:items-center"
            >
                <Container className="pt-28 pb-10 lg:pt-24 lg:pb-6">
                    <Link
                        href={home()}
                        className="mb-6 inline-flex min-h-11 items-center gap-2 text-sm text-foreground/70 transition-colors hover:text-primary lg:hidden"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back to Estatell
                    </Link>
                    <div className="grid items-center gap-8 lg:grid-cols-2 lg:gap-12">
                        <header className="min-w-0">
                            <p className="text-xs font-semibold tracking-widest text-foreground/70 uppercase">
                                Contact us
                            </p>
                            <h1 className="mt-4 max-w-lg text-4xl leading-tight font-medium tracking-tighter text-balance sm:text-5xl">
                                Good hospitality starts with a conversation.
                            </h1>
                            <p className="mt-4 max-w-md text-base leading-relaxed text-foreground/70 sm:text-lg">
                                Tell us about your property. We’ll help you find
                                a simpler way to run it.
                            </p>
                            <div className="relative mx-auto mt-5 max-w-56">
                                <div
                                    aria-hidden="true"
                                    className="absolute inset-x-4 top-12 bottom-8 rounded-full bg-primary/10"
                                />
                                <div className="relative ml-auto flex w-fit max-w-56 items-start gap-2 rounded-2xl rounded-bl-sm border border-border bg-card px-4 py-3 text-sm leading-relaxed shadow-sm motion-safe:animate-in motion-safe:duration-700 motion-safe:fade-in motion-safe:slide-in-from-bottom-2">
                                    <p>
                                        {sent
                                            ? 'Thank you! Our team will be in touch.'
                                            : 'Welcome! How can we help?'}
                                    </p>
                                </div>
                                <img
                                    src="/images/landing/contact-concierge.webp"
                                    alt="A friendly hotel concierge waving hello from the reception desk"
                                    width={900}
                                    height={900}
                                    fetchPriority="high"
                                    className="relative mt-2 h-auto w-full"
                                />
                            </div>
                            <p className="mt-3 hidden text-center text-sm text-foreground/60 lg:block">
                                Made for the people behind every great stay.
                            </p>
                        </header>
                        <section
                            aria-labelledby="enquiry-heading"
                            className="min-w-0 rounded-3xl border border-border bg-card p-5 shadow-sm sm:p-6"
                        >
                            {sent ? (
                                <div
                                    role="status"
                                    className="flex flex-col items-center gap-5 px-2 py-10 text-center sm:py-12"
                                >
                                    <span className="flex size-16 items-center justify-center rounded-full bg-primary/10 text-primary">
                                        <Check
                                            className="size-8"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <h2
                                        id="enquiry-heading"
                                        className="max-w-sm text-2xl font-medium tracking-tight sm:text-3xl"
                                    >
                                        Thank you for getting in touch.
                                    </h2>
                                    <p className="max-w-sm text-base leading-relaxed text-foreground/70">
                                        Your enquiry is with our team. We’ll
                                        reply by email or call the number you
                                        provided.
                                    </p>
                                    <Link
                                        href={home()}
                                        className="mt-1 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-primary underline underline-offset-4 hover:text-foreground"
                                    >
                                        Back to Estatell
                                    </Link>
                                </div>
                            ) : (
                                <>
                                    <div className="mb-5">
                                        <p className="text-xs font-semibold tracking-widest text-foreground/70 uppercase">
                                            Let’s get to know you
                                        </p>
                                        <h2
                                            id="enquiry-heading"
                                            className="mt-2 text-2xl font-medium tracking-tight sm:text-3xl"
                                        >
                                            Tell us about your hotel.
                                        </h2>
                                        <p className="mt-2 text-sm leading-relaxed text-foreground/70">
                                            A few details to get the
                                            conversation started.
                                        </p>
                                    </div>
                                    <Form
                                        {...store.form()}
                                        disableWhileProcessing
                                    >
                                        {({ processing, errors }) => (
                                            <div className="grid gap-4">
                                                <fieldset className="min-w-0">
                                                    <legend className="sr-only">
                                                        Your property and
                                                        contact details
                                                    </legend>
                                                    <div className="grid gap-4 sm:grid-cols-2">
                                                        {fields.map(
                                                            ({
                                                                name,
                                                                label,
                                                                ...input
                                                            }) => (
                                                                <div
                                                                    key={name}
                                                                    className="grid min-w-0 gap-2"
                                                                >
                                                                    <Label
                                                                        htmlFor={
                                                                            name
                                                                        }
                                                                    >
                                                                        {label}
                                                                    </Label>
                                                                    <Input
                                                                        id={
                                                                            name
                                                                        }
                                                                        name={
                                                                            name
                                                                        }
                                                                        required
                                                                        aria-invalid={
                                                                            !!errors[
                                                                                name
                                                                            ]
                                                                        }
                                                                        aria-describedby={
                                                                            errors[
                                                                                name
                                                                            ]
                                                                                ? `${name}-error`
                                                                                : undefined
                                                                        }
                                                                        className="h-11"
                                                                        {...input}
                                                                    />
                                                                    <InputError
                                                                        id={`${name}-error`}
                                                                        message={
                                                                            errors[
                                                                                name
                                                                            ]
                                                                        }
                                                                    />
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </fieldset>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="message">
                                                        How can we help?
                                                    </Label>
                                                    <Textarea
                                                        id="message"
                                                        name="message"
                                                        rows={3}
                                                        placeholder="Tell us what you’re looking for…"
                                                        maxLength={2000}
                                                        required
                                                        aria-invalid={
                                                            !!errors.message
                                                        }
                                                        aria-describedby={
                                                            errors.message
                                                                ? 'message-error'
                                                                : undefined
                                                        }
                                                    />
                                                    <InputError
                                                        id="message-error"
                                                        message={errors.message}
                                                    />
                                                </div>

                                                {/* Honeypot: people never see it, bots fill it. */}
                                                <div
                                                    aria-hidden="true"
                                                    className="hidden"
                                                >
                                                    <label htmlFor="website">
                                                        Leave this empty
                                                    </label>
                                                    <input
                                                        id="website"
                                                        name="website"
                                                        type="text"
                                                        tabIndex={-1}
                                                        autoComplete="off"
                                                    />
                                                </div>
                                                <InputError
                                                    message={errors.website}
                                                />

                                                <Button
                                                    type="submit"
                                                    size="lg"
                                                    className="h-12 w-full rounded-xl"
                                                    disabled={processing}
                                                    focusableWhenDisabled
                                                >
                                                    {processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Send enquiry
                                                    <ArrowRight
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                </Button>
                                                <p className="flex items-start justify-center gap-2 text-xs leading-relaxed text-foreground/60">
                                                    <LockKeyhole
                                                        className="mt-0.5 size-3.5 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span>
                                                        We’ll use your details
                                                        to respond to your
                                                        enquiry. Read our{' '}
                                                        <Link
                                                            href={privacy()}
                                                            className="underline underline-offset-4 hover:text-foreground"
                                                        >
                                                            Privacy Policy
                                                        </Link>
                                                        .
                                                    </span>
                                                </p>
                                            </div>
                                        )}
                                    </Form>
                                </>
                            )}
                        </section>
                    </div>
                </Container>
            </main>

            <footer className="dark bg-background pt-10 pb-[calc(2.5rem+env(safe-area-inset-bottom))] text-foreground sm:py-14">
                <Container>
                    <div className="flex flex-col gap-8 border-b border-border pb-8 sm:flex-row sm:items-center sm:justify-between">
                        <Link
                            href={home()}
                            className="inline-flex min-h-11 w-fit items-center font-archivo-black text-3xl font-normal tracking-tighter"
                        >
                            Estatell
                        </Link>
                        <nav
                            aria-label="Legal"
                            className="flex flex-wrap gap-x-6 gap-y-2 text-sm"
                        >
                            <Link
                                href={contact()}
                                aria-current="page"
                                className="inline-flex min-h-11 items-center text-chart-1"
                            >
                                Contact Us
                            </Link>
                            <Link
                                href={terms()}
                                className="inline-flex min-h-11 items-center text-foreground/75 hover:text-chart-1"
                            >
                                Terms and Conditions
                            </Link>
                            <Link
                                href={privacy()}
                                className="inline-flex min-h-11 items-center text-foreground/75 hover:text-chart-1"
                            >
                                Privacy Policy
                            </Link>
                        </nav>
                    </div>
                </Container>
            </footer>
        </>
    );
}
