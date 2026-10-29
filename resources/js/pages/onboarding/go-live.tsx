import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Flag, Hourglass, Rocket, Send, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import GoLiveController from '@/actions/App/Http/Controllers/Onboarding/GoLiveController';
import Heading from '@/components/heading';
import { ReviewPhaseCard } from '@/components/pms/onboarding/review-phase-card';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
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
import { useIsImpersonating } from '@/hooks/use-is-impersonating';

type Review = App.Data.Onboarding.GoLiveReviewData;
type Request = App.Data.Onboarding.GoLiveRequestData;

const FORM_ID = 'go-live-form';

export default function GoLive({
    review,
    awaitingApproval,
    requiresApproval,
    pendingRequest,
    sentBack,
    migrationWindowDays,
}: {
    review: Review;
    awaitingApproval: boolean;
    requiresApproval: boolean;
    pendingRequest: Request | null;
    sentBack: Request | null;
    migrationWindowDays: number;
}) {
    const [confirming, setConfirming] = useState(false);
    const impersonating = useIsImpersonating();
    const requiredLeft = review.missing.length;
    const missingSections = review.missing.join(', ');
    const showAction = !awaitingApproval && !impersonating;

    // The Review page is a to-do list and a preview: it has no form of its own. Go live sits in the footer.
    setLayoutProps<OnboardingLayoutProps>({
        step: 'review',
        footer: 'back',
        formId: null,
        processing: false,
    });

    // The footer button carries a handler, so it is set in an effect that depends only on plain values. Setting it
    // during render would write to the layout store on every render.
    useEffect(() => {
        setLayoutProps<OnboardingLayoutProps>({
            primaryAction: showAction
                ? {
                      label: requiresApproval ? 'Send for approval' : 'Go live',
                      icon: requiresApproval ? Send : Rocket,
                      disabled: !review.ready,
                      tooltip: review.ready
                          ? undefined
                          : `Finish ${missingSections} first`,
                      onClick: () => setConfirming(true),
                  }
                : null,
        });
    }, [showAction, requiresApproval, review.ready, missingSections]);

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

                {sentBack && <SentBackAlert request={sentBack} />}

                {awaitingApproval && <WaitingAlert request={pendingRequest} />}

                {awaitingApproval ? (
                    <div className="grid gap-4 md:grid-cols-2">
                        {review.phases.map((phase) => (
                            <ReviewPhaseCard key={phase.value} phase={phase} />
                        ))}
                    </div>
                ) : (
                    <Form
                        id={FORM_ID}
                        className="flex flex-col gap-6"
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

                                <div className="grid gap-4 md:grid-cols-2">
                                    {review.phases.map((phase) => (
                                        <ReviewPhaseCard
                                            key={phase.value}
                                            phase={phase}
                                        />
                                    ))}
                                </div>

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
                                    {migrationWindowDays > 0 && (
                                        <p>
                                            For {migrationWindowDays} days after
                                            go-live you can bring in guests in
                                            the house, deposits and company
                                            balances from your old system.
                                        </p>
                                    )}
                                </div>

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

function SentBackAlert({ request }: { request: Request }) {
    return (
        <Alert>
            <Flag />
            <AlertTitle>Not approved yet</AlertTitle>
            <AlertDescription className="flex flex-col gap-1">
                {request.note && <p>{request.note}</p>}
                <p>
                    Reviewed by estatell support on {request.reviewedOn}. Fix
                    the flagged sections, then send it again.
                </p>
            </AlertDescription>
        </Alert>
    );
}

function WaitingAlert({ request }: { request: Request | null }) {
    const [withdrawing, setWithdrawing] = useState(false);
    const impersonating = useIsImpersonating();

    return (
        <Alert>
            <Hourglass />
            <AlertTitle>Waiting for approval</AlertTitle>
            <AlertDescription className="flex flex-col gap-3">
                <p>
                    {request ? `Sent on ${request.sentOn}. ` : ''}We usually
                    review within 1 working day. We'll email you when you're
                    live.
                </p>
                {!impersonating && (
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setWithdrawing(true)}
                        >
                            Withdraw to make changes
                        </Button>
                    </div>
                )}
            </AlertDescription>
            <AlertDialog open={withdrawing} onOpenChange={setWithdrawing}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Withdraw your request?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Your sections open for editing again. Send the setup
                            for approval when you are ready.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...GoLiveController.withdraw.form()}
                        onSuccess={() => setWithdrawing(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={processing}>
                                    Cancel
                                </AlertDialogCancel>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && <Spinner />}
                                    Withdraw
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </Alert>
    );
}

GoLive.layout = { width: '8xl' };
