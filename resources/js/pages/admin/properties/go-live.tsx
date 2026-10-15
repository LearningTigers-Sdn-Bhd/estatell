import { Form, Head, Link } from '@inertiajs/react';
import { CircleCheck, TriangleAlert, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { ReviewPhaseCard } from '@/components/pms/onboarding/review-phase-card';
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
import { Card, CardContent } from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes/admin';
import { approve, reject, show } from '@/routes/admin/properties/go-live';
import { show as viewAs } from '@/routes/view-as';
import {
    index as propertiesIndex,
    show as propertyShow,
} from '@/routes/admin/properties';

type GoLive = App.Data.Platform.AdminGoLiveReviewData;

export default function PropertyGoLive({ goLive }: { goLive: GoLive }) {
    const { review } = goLive;
    const [flagged, setFlagged] = useState<ReadonlySet<string>>(new Set());
    const [approving, setApproving] = useState(false);
    const [sendingBack, setSendingBack] = useState(false);

    const flaggedRows = review.phases
        .flatMap((phase) => phase.rows)
        .filter((row) => flagged.has(row.value));

    const toggle = (value: string, on: boolean) =>
        setFlagged((current) => {
            const next = new Set(current);

            if (on) {
                next.add(value);
            } else {
                next.delete(value);
            }

            return next;
        });

    return (
        <>
            <Head title={`Review ${goLive.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`Review ${goLive.name}`}
                    description={`Sent by ${goLive.submittedBy} on ${goLive.sentOn}${goLive.partnerName ? ` · Partner: ${goLive.partnerName}` : ''}`}
                    back={{
                        href: propertyShow(goLive.code),
                        label: goLive.name,
                    }}
                    actions={
                        <>
                            <Link
                                href={viewAs(goLive.code)}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                View as
                            </Link>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSendingBack(true)}
                            >
                                <Undo2 data-icon="inline-start" />
                                Send back
                            </Button>
                            <Button
                                type="button"
                                onClick={() => setApproving(true)}
                            >
                                <CircleCheck data-icon="inline-start" />
                                Approve
                            </Button>
                        </>
                    }
                />

                {!review.ready && (
                    <Card>
                        <CardContent className="flex items-start gap-2 text-sm">
                            <TriangleAlert
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            Some required sections are no longer done:{' '}
                            {review.missing.join(', ')}. The property cannot go
                            live until they are fixed. Send it back to the
                            owner.
                        </CardContent>
                    </Card>
                )}

                {review.warnings.length > 0 && (
                    <Card>
                        <CardContent>
                            <ul className="flex flex-col gap-3">
                                {review.warnings.map((warning) => (
                                    <li
                                        key={`${warning.step}-${warning.message}`}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <TriangleAlert
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-warning"
                                        />
                                        {warning.message}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {review.phases.map((phase) => (
                        <ReviewPhaseCard
                            key={phase.value}
                            phase={phase}
                            flagging={{ flagged, onToggle: toggle }}
                        />
                    ))}
                </div>
            </div>

            <AlertDialog open={approving} onOpenChange={setApproving}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Approve {goLive.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The first business day opens on the date below.{' '}
                            {review.signInCount > 0
                                ? `${review.signInCount} ${review.signInCount === 1 ? 'staff member gets' : 'staff members get'} new sign-in details. `
                                : ''}
                            The owner is emailed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...approve.form(goLive.code)}
                        onSuccess={() => setApproving(false)}
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-6">
                                <Field data-invalid={!!errors.opening_date}>
                                    <FieldLabel htmlFor="opening_date">
                                        Opening date
                                    </FieldLabel>
                                    <Input
                                        id="opening_date"
                                        name="opening_date"
                                        type="date"
                                        defaultValue={goLive.today}
                                        max={goLive.today}
                                        required
                                    />
                                    <FieldDescription>
                                        Only change this if the owner asked to
                                        add stays from before today.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.opening_date}
                                    </FieldError>
                                </Field>
                                <AlertDialogFooter>
                                    <AlertDialogCancel disabled={processing}>
                                        Cancel
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        disabled={processing || !review.ready}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <CircleCheck data-icon="inline-start" />
                                        )}
                                        Approve
                                    </Button>
                                </AlertDialogFooter>
                            </div>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={sendingBack} onOpenChange={setSendingBack}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Send {goLive.name} back?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The owner can edit again and sees your note.
                            {flaggedRows.length > 0
                                ? ` Flagged: ${flaggedRows.map((row) => row.label).join(', ')}.`
                                : ' Tick rows on the page to flag sections for the owner.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...reject.form(goLive.code)}
                        onSuccess={() => setSendingBack(false)}
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-6">
                                {flaggedRows.map((row) => (
                                    <input
                                        key={row.value}
                                        type="hidden"
                                        name="flags[]"
                                        value={row.value}
                                    />
                                ))}
                                <Field data-invalid={!!errors.note}>
                                    <FieldLabel htmlFor="note">
                                        Note to the owner
                                    </FieldLabel>
                                    <Textarea
                                        id="note"
                                        name="note"
                                        rows={4}
                                        required
                                        maxLength={1000}
                                        placeholder="e.g. Please check the room prices and add your tax registration number."
                                    />
                                    <FieldError>{errors.note}</FieldError>
                                </Field>
                                <AlertDialogFooter>
                                    <AlertDialogCancel disabled={processing}>
                                        Cancel
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Undo2 data-icon="inline-start" />
                                        )}
                                        Send back
                                    </Button>
                                </AlertDialogFooter>
                            </div>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

PropertyGoLive.layout = (props: { goLive: GoLive }) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: propertiesIndex() },
        { title: props.goLive.name, href: propertyShow(props.goLive.code) },
        { title: 'Go-live review', href: show(props.goLive.code) },
    ],
});
