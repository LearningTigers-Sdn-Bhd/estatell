import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    CircleCheck,
    CircleX,
    Circle,
    Lock,
    Minus,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import GoLiveController from '@/actions/App/Http/Controllers/Onboarding/GoLiveController';
import Heading from '@/components/heading';
import {
    stateWord,
    type OnboardingLayoutProps,
} from '@/components/pms/onboarding/onboarding-steps';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';

type Review = App.Data.Onboarding.GoLiveReviewData;
type Row = App.Data.Onboarding.GoLiveReviewRowData;

const FORM_ID = 'go-live-form';

export default function GoLive({
    review,
    awaitingApproval,
    requiresApproval,
}: {
    review: Review;
    awaitingApproval: boolean;
    requiresApproval: boolean;
}) {
    const [confirming, setConfirming] = useState(false);
    const impersonating = useIsImpersonating();
    const requiredLeft = review.missing.length;

    // The Review page is a to-do list and a preview: it has no form of its own on the layout's footer.
    setLayoutProps<OnboardingLayoutProps>({
        step: 'review',
        footer: 'back',
        formId: null,
        processing: false,
    });

    const goLiveButton = (
        <Button
            type="button"
            disabled={!review.ready}
            focusableWhenDisabled
            onClick={() => setConfirming(true)}
        >
            {requiresApproval ? 'Send for approval' : 'Go live'}
        </Button>
    );

    return (
        <>
            <Head title="Review" />
            <div className="flex flex-col gap-6">
                <Heading
                    title="Review"
                    description={
                        review.ready
                            ? 'Everything is set. Go live when you are ready.'
                            : `Almost there. Finish ${requiredLeft} required ${requiredLeft === 1 ? 'section' : 'sections'} to go live.`
                    }
                />

                {awaitingApproval ? (
                    <Alert>
                        <AlertTitle>Waiting for approval</AlertTitle>
                        <AlertDescription>
                            Your request was sent. You can use the pages that
                            run the property once the team approves it.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Form
                        id={FORM_ID}
                        {...GoLiveController.store.form()}
                        onFinish={() => setConfirming(false)}
                    >
                        {({ processing }) => (
                            <>
                                {review.warnings.length > 0 && (
                                    <Card>
                                        <CardContent>
                                            <ul className="flex flex-col gap-3">
                                                {review.warnings.map(
                                                    (warning) => (
                                                        <li
                                                            key={`${warning.step}-${warning.message}`}
                                                            className="flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between"
                                                        >
                                                            <span className="flex items-start gap-2">
                                                                <TriangleAlert
                                                                    aria-hidden
                                                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                                                />
                                                                {
                                                                    warning.message
                                                                }
                                                            </span>
                                                            <Link
                                                                href={
                                                                    warning.url
                                                                }
                                                                className={buttonVariants(
                                                                    {
                                                                        variant:
                                                                            'outline',
                                                                        size: 'sm',
                                                                    },
                                                                )}
                                                            >
                                                                Fix in{' '}
                                                                {
                                                                    warning.stepLabel
                                                                }{' '}
                                                                →
                                                            </Link>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </CardContent>
                                    </Card>
                                )}

                                {review.phases.map((phase) => (
                                    <section
                                        key={phase.value}
                                        aria-labelledby={`phase-${phase.value}`}
                                        className="flex flex-col gap-2"
                                    >
                                        <h2
                                            id={`phase-${phase.value}`}
                                            className="text-sm font-medium text-muted-foreground"
                                        >
                                            {phase.label}
                                        </h2>
                                        <Card>
                                            <CardContent>
                                                <ul className="divide-y">
                                                    {phase.rows.map((row) => (
                                                        <ReviewRow
                                                            key={row.value}
                                                            row={row}
                                                        />
                                                    ))}
                                                </ul>
                                            </CardContent>
                                        </Card>
                                    </section>
                                ))}

                                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                                    <p>
                                        {review.signInCount > 0
                                            ? `${review.signInCount} ${review.signInCount === 1 ? 'staff member gets' : 'staff members get'} sign-in details when you go live.`
                                            : 'No staff need sign-in details.'}
                                    </p>
                                    <p>
                                        Your first business day opens on the day
                                        you go live.
                                    </p>
                                </div>

                                {!impersonating && (
                                    <div className="flex justify-end">
                                        {review.ready ? (
                                            goLiveButton
                                        ) : (
                                            <Tooltip>
                                                <TooltipTrigger
                                                    render={goLiveButton}
                                                />
                                                <TooltipContent>
                                                    {`Finish ${review.missing.join(', ')} first`}
                                                </TooltipContent>
                                            </Tooltip>
                                        )}
                                    </div>
                                )}

                                <AlertDialog
                                    open={confirming}
                                    onOpenChange={(open) =>
                                        !processing && setConfirming(open)
                                    }
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Go live today?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Your first business day opens on{' '}
                                                {review.firstBusinessDate}.
                                                Country, currency and time zone
                                                can't be changed after this.
                                                {review.signInCount > 0 &&
                                                    ` ${review.signInCount} ${review.signInCount === 1 ? 'staff member gets' : 'staff members get'} sign-in details.`}
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel
                                                disabled={processing}
                                            >
                                                Cancel
                                            </AlertDialogCancel>
                                            <Button
                                                type="submit"
                                                form={FORM_ID}
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                {requiresApproval
                                                    ? 'Send for approval'
                                                    : 'Go live'}
                                            </Button>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

function ReviewRow({ row }: { row: Row }) {
    const word = row.skipped ? 'Skipped' : (stateWord[row.state] ?? '');

    return (
        <li className="flex flex-col gap-2 py-3 md:grid md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)_auto] md:items-center md:gap-4">
            <div className="flex items-center gap-2">
                <StateIcon row={row} />
                <span className="font-medium">{row.label}</span>
                <span className="sr-only">{word}</span>
            </div>
            <p className="text-sm text-muted-foreground">
                {row.summary !== '' && row.summary}
            </p>
            <Link
                href={row.actionUrl}
                className={buttonVariants({
                    variant: row.actionLabel === 'Edit' ? 'ghost' : 'outline',
                    size: 'sm',
                })}
            >
                {row.actionLabel}
                <span className="sr-only"> {row.label}</span>
            </Link>
        </li>
    );
}

function StateIcon({ row }: { row: Row }) {
    if (row.skipped) {
        return (
            <Minus
                aria-hidden
                className="size-4 shrink-0 text-muted-foreground"
            />
        );
    }

    switch (row.state) {
        case 'done':
            return (
                <CircleCheck
                    aria-hidden
                    className="size-4 shrink-0 text-success"
                />
            );
        case 'locked':
            return (
                <Lock
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            );
        case 'needs_attention':
            return (
                <TriangleAlert
                    aria-hidden
                    className="size-4 shrink-0 text-warning"
                />
            );
        default:
            return row.isRequired ? (
                <CircleX
                    aria-hidden
                    className="size-4 shrink-0 text-destructive"
                />
            ) : (
                <Circle
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            );
    }
}
