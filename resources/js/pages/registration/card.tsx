import { Head, useForm } from '@inertiajs/react';
import { Ban, Download, Printer, Tablet } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { IdentityDocumentFields } from '@/components/pms/registration-card/identity-document-fields';
import { MyKadScanner } from '@/components/pms/mykad-scanner';
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
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupText,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import {
    index as reservationsIndex,
    guests as reservationGuests,
} from '@/routes/reservations';
import {
    blank,
    guest,
    pdf,
    update,
    voidMethod as voidCard,
} from '@/routes/registration';

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

function Details({
    fields,
}: {
    fields: { label: string; value: string | null }[];
}) {
    return (
        <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
            {fields.map((field) => (
                <div key={field.label} className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">{field.label}</dt>
                    <dd className="font-medium tabular-nums">{field.value}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * A draft: the current answers, read-only, and the two things staff set before handing over:
 * the ID document, the vehicle plate and the remarks. The guest fills the rest on the guest screen.
 */
function DraftCard({
    card,
    identityTypes,
    countries,
}: {
    card: Card;
    identityTypes: EnumOption[];
    countries: EnumOption[];
}) {
    const shown = new Set(card.fields.map((field) => field.field));
    const form = useForm({
        identity_document_id:
            card.cardDocumentId !== null ? String(card.cardDocumentId) : '',
        identity: {
            type: 'mykad',
            number: '',
            issuing_country_code: '',
            expires_on: '',
        },
        vehicle_plate: (card.values.vehicle_plate as string) ?? '',
        remarks: (card.values.remarks as string) ?? '',
    });
    const errors = form.errors as Record<string, string>;

    return (
        <div className="grid items-start gap-6 lg:grid-cols-2">
            <Details fields={card.draftFields ?? []} />
            {(shown.has('identity_document') ||
                shown.has('vehicle_plate') ||
                shown.has('remarks')) && (
                <form
                    className="flex flex-col gap-4 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update.url({ unitGuest: card.unitGuestId }), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <h4 className="text-sm font-medium">Before handing over</h4>
                    <FieldGroup className="grid gap-4">
                        {shown.has('identity_document') && (
                            <>
                                <MyKadScanner
                                    compact
                                    onScan={(card) => {
                                        form.setData(
                                            'identity_document_id',
                                            '',
                                        );
                                        form.setData('identity', {
                                            type: 'mykad',
                                            number:
                                                card.formatted_ic ||
                                                card.ic_number,
                                            issuing_country_code: 'MY',
                                            expires_on: '',
                                        });
                                    }}
                                />
                                <IdentityDocumentFields
                                    documents={card.documents}
                                    documentId={form.data.identity_document_id}
                                    identity={form.data.identity}
                                    onDocumentChange={(value) =>
                                        form.setData(
                                            'identity_document_id',
                                            value,
                                        )
                                    }
                                    onIdentityChange={(value) =>
                                        form.setData('identity', value)
                                    }
                                    identityTypes={identityTypes}
                                    countries={countries}
                                    errors={errors}
                                    label="ID document"
                                />
                            </>
                        )}
                        {shown.has('vehicle_plate') && (
                            <Field data-invalid={!!errors.vehicle_plate}>
                                <FieldLabel htmlFor="card-vehicle_plate">
                                    Vehicle plate
                                </FieldLabel>
                                <Input
                                    id="card-vehicle_plate"
                                    placeholder="WXY 1234"
                                    value={form.data.vehicle_plate}
                                    onChange={(event) =>
                                        form.setData(
                                            'vehicle_plate',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!errors.vehicle_plate}
                                />
                                <FieldError>{errors.vehicle_plate}</FieldError>
                            </Field>
                        )}
                        {shown.has('remarks') && (
                            <Field data-invalid={!!errors.remarks}>
                                <FieldLabel htmlFor="card-remarks">
                                    Remarks
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupTextarea
                                        id="card-remarks"
                                        value={form.data.remarks}
                                        onChange={(event) =>
                                            form.setData(
                                                'remarks',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={500}
                                        rows={3}
                                        placeholder="Requests or notes about the stay"
                                        aria-invalid={!!errors.remarks}
                                    />
                                    <InputGroupAddon
                                        align="block-end"
                                        className="justify-end"
                                    >
                                        <InputGroupText className="tabular-nums">
                                            {form.data.remarks.length}/500
                                        </InputGroupText>
                                    </InputGroupAddon>
                                </InputGroup>
                                <FieldError>{errors.remarks}</FieldError>
                            </Field>
                        )}
                    </FieldGroup>
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Save
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}

/** A signed card: what was frozen at signing. It is never edited. */
function SignedCard({ card }: { card: Card }) {
    return (
        <div className="grid items-start gap-6 lg:grid-cols-2">
            <Details fields={card.signedFields ?? []} />
            <div className="flex flex-col gap-3">
                <div className="max-h-[32rem] overflow-y-auto rounded-lg border p-4">
                    <TermsPreview
                        body={card.termsText}
                        values={{}}
                        ticks={card.signedTicks ?? []}
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    Signed by {card.signerName}
                    {card.signedAt && (
                        <>
                            {' '}
                            on {new Date(card.signedAt).toLocaleString('en-GB')}
                        </>
                    )}
                    .
                </p>
            </div>
        </div>
    );
}

/** The staff page of a card: check it here, then hand it to the guest. Nobody signs on this page. */
export default function RegistrationCardPage({
    card,
    identityTypes,
    countries,
}: {
    card: Card;
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
}) {
    const [voiding, setVoiding] = useState(false);
    const canCorrectMoney = useCanCorrectMoney();

    return (
        <>
            <Head title="Registration card" />
            <div className="flex max-w-6xl flex-col gap-4 p-4">
                <PageHeader
                    title={card.guestName}
                    description={`${card.unitLabel}${card.number ? ` · ${card.number}` : ''}`}
                    back={{
                        href: reservationGuests.url({
                            reservation: card.reservationId,
                        }),
                        label: 'reservation',
                    }}
                    badge={<StatusBadge status={card.status} />}
                    actions={
                        <>
                            {card.canVoid && canCorrectMoney && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setVoiding(true)}
                                >
                                    <Ban data-icon="inline-start" />
                                    Void card
                                </Button>
                            )}
                            {card.status.value === 'draft' && (
                                <a
                                    href={blank.url(
                                        { unitGuest: card.unitGuestId },
                                        { query: { locale: card.locale } },
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    <Printer data-icon="inline-start" />
                                    Print blank form
                                </a>
                            )}
                            {card.status.value === 'signed' && (
                                <a
                                    href={pdf.url({
                                        unitGuest: card.unitGuestId,
                                    })}
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    <Download data-icon="inline-start" />
                                    Download PDF
                                </a>
                            )}
                            {card.status.value === 'draft' && (
                                <a
                                    href={guest.url({
                                        unitGuest: card.unitGuestId,
                                    })}
                                    target="_blank"
                                    rel="noreferrer"
                                    className={buttonVariants()}
                                >
                                    <Tablet data-icon="inline-start" />
                                    Hand to guest
                                </a>
                            )}
                        </>
                    }
                />
                {card.status.value === 'signed' ? (
                    <SignedCard card={card} />
                ) : (
                    <DraftCard
                        key={card.cardId}
                        card={card}
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
