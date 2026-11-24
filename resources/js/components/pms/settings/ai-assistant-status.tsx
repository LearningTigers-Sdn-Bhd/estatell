import { Link, useForm } from '@inertiajs/react';
import { KeyRound, Plug, TriangleAlert } from 'lucide-react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { general } from '@/routes/settings/ai-assistant';
import { test } from '@/routes/settings/ai-assistant/key';

/** Date and time of the last test, in the viewer's locale. */
function testedAt(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular-nums">{children}</dd>
        </div>
    );
}

/**
 * The status rail on every tab of Settings › AI assistant: a warning when there is no key or OpenAI refused it, the key
 * and its last test, how many places are on, this month's estimated spend against the cap, and Test key.
 * `showKeyLink` adds a link to the General tab in the warning, on the tabs that do not hold the key form.
 */
export function AiAssistantStatusRail({
    status,
    showKeyLink,
}: {
    status: App.Data.AiAssistant.AiAssistantStatusData;
    showKeyLink: boolean;
}) {
    const keyTest = useForm({});
    const hasKey = status.maskedKey !== null;
    const failing = status.keyStatus.value === 'failing';

    return (
        <div className="flex flex-col gap-6 *:not-first:border-t *:not-first:pt-4">
            {(!hasKey || failing) && (
                <Alert variant={failing ? 'destructive' : 'default'}>
                    {failing ? <TriangleAlert /> : <KeyRound />}
                    <AlertTitle>
                        {failing
                            ? 'OpenAI refused this key.'
                            : 'Add your OpenAI key to start the assistant.'}
                    </AlertTitle>
                    <AlertDescription>
                        {failing
                            ? 'Chats hand over to staff until you fix the key or add credit, then test it again.'
                            : 'Every place stays off until a key is saved.'}
                        {showKeyLink && (
                            <Link href={general()} className="underline">
                                {failing ? 'Change key' : 'Add key'}
                            </Link>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            <section className="flex flex-col gap-3">
                <h2 className="ps-3 pt-2 text-base font-medium">Status</h2>
                <dl className="flex flex-col gap-3 text-sm">
                    <Row label="Key">
                        <span className="font-mono">
                            {status.maskedKey ?? '—'}
                        </span>
                    </Row>
                    <Row label="Key status">
                        <StatusBadge status={status.keyStatus} />
                    </Row>
                    <Row label="Last tested">
                        {status.checkedAt
                            ? testedAt(status.checkedAt)
                            : 'Never'}
                    </Row>
                    <Row label="Places on">
                        {status.placesOn} of {status.placesTotal}
                    </Row>
                </dl>
                <Button
                    type="button"
                    variant="outline"
                    disabled={!hasKey || keyTest.processing}
                    focusableWhenDisabled
                    onClick={() =>
                        keyTest.post(test.url(), { preserveScroll: true })
                    }
                >
                    {keyTest.processing ? <Spinner /> : <Plug />}
                    Test key
                </Button>
            </section>

            <section className="flex flex-col gap-3">
                <div className="flex flex-col gap-1 ps-3 pt-2">
                    <h2 className="text-base font-medium">This month</h2>
                    <p className="text-sm text-muted-foreground">
                        An estimate from tokens. Your OpenAI bill shows the
                        exact amount.
                    </p>
                </div>
                <dl className="flex flex-col gap-3 text-sm">
                    <Row label="Spent">US$ {status.spentUsd}</Row>
                    <Row label="Cap">
                        {status.capUsd === null
                            ? 'No cap'
                            : `${status.capPercent}% of US$ ${status.capUsd}`}
                    </Row>
                </dl>
                {status.capPercent !== null && (
                    <Progress
                        value={status.capPercent}
                        aria-label="Monthly cap used"
                    />
                )}
            </section>
        </div>
    );
}
