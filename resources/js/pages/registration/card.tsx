import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import type { EnumOption } from '@/components/pms/enum-select';
import { RegistrationCardForm } from '@/components/pms/registration-card/registration-card-form';
import { StatusBadge } from '@/components/pms/status-badge';
import { TermsPreview } from '@/components/pms/registration-card/terms-preview';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    index as reservationsIndex,
    show as showReservation,
} from '@/routes/reservations';
import { voidMethod as voidCard } from '@/routes/registration';

type Card = App.Data.Registration.RegistrationCardPageData;

function VoidDialog({
    card,
    open,
    onOpenChange,
}: {
    card: Card;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Void this card?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The signed text stays on record. A new card can then be
                        signed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(
                            voidCard.url({ unitGuest: card.unitGuestId }),
                            {
                                onSuccess: () => {
                                    form.reset();
                                    onOpenChange(false);
                                },
                            },
                        );
                    }}
                >
                    <Field data-invalid={!!form.errors.reason}>
                        <FieldLabel htmlFor="void-reason">Reason</FieldLabel>
                        <Textarea
                            id="void-reason"
                            maxLength={250}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            aria-invalid={!!form.errors.reason}
                        />
                        <FieldError>{form.errors.reason}</FieldError>
                    </Field>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Void card
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** A signed card: what was frozen at signing. It is never edited. */
function SignedCard({ card }: { card: Card }) {
    return (
        <div className="flex flex-col gap-6">
            <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                {(card.signedFields ?? []).map((field) => (
                    <div key={field.label} className="flex flex-col gap-0.5">
                        <dt className="text-muted-foreground">{field.label}</dt>
                        <dd className="font-medium">{field.value}</dd>
                    </div>
                ))}
            </dl>
            <div className="max-h-72 overflow-y-auto rounded-lg border p-4">
                <TermsPreview body={card.termsText} values={{}} />
            </div>
            <p className="text-sm text-muted-foreground">
                Signed by {card.signerName}
                {card.signedAt && (
                    <> on {new Date(card.signedAt).toLocaleString('en-GB')}</>
                )}
                .
            </p>
        </div>
    );
}

export default function RegistrationCardPage({
    card,
    genders,
    identityTypes,
    countries,
}: {
    card: Card;
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
}) {
    const [voiding, setVoiding] = useState(false);

    return (
        <>
            <Head title="Registration card" />
            <div className="flex max-w-6xl flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={card.guestName}
                        description={`${card.unitLabel}${card.number ? ` · ${card.number}` : ''}`}
                        badge={<StatusBadge status={card.status} />}
                        className="mb-0"
                    />
                    <div className="flex items-center gap-2">
                        {card.canVoid && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setVoiding(true)}
                            >
                                Void card
                            </Button>
                        )}
                        <Link
                            href={showReservation.url({
                                reservation: card.reservationId,
                            })}
                            className={buttonVariants({ variant: 'ghost' })}
                        >
                            Back to reservation
                        </Link>
                    </div>
                </div>
                {card.status.value === 'signed' ? (
                    <SignedCard card={card} />
                ) : (
                    <RegistrationCardForm
                        key={card.cardId}
                        card={card}
                        genders={genders}
                        identityTypes={identityTypes}
                        countries={countries}
                    />
                )}
                <VoidDialog
                    card={card}
                    open={voiding}
                    onOpenChange={setVoiding}
                />
            </div>
        </>
    );
}

RegistrationCardPage.layout = {
    breadcrumbs: [{ title: 'Reservations', href: reservationsIndex() }],
};
