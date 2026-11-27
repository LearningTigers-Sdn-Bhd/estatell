import { Link, useForm } from '@inertiajs/react';
import {
    Activity,
    CircleAlert,
    ExternalLink,
    Plug,
    Shield,
} from 'lucide-react';
import {
    ENVIRONMENT_LABEL,
    type EInvoiceSetting,
} from '@/components/pms/einvoice/settings-types';
import { PanelHeading } from '@/components/pms/panel-heading';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { business, test as testRoute } from '@/routes/settings/e-invoice';

/**
 * The right rail of every Settings › E-invoicing tab: whether e-invoices can go out now, what is still missing, the
 * connection test and LHDN's main rules. It reads the saved settings, not the open form.
 */
export function EInvoiceStatusRail({
    setting,
    problems,
    onBusinessTab,
}: {
    setting: EInvoiceSetting | null;
    problems: Record<string, string>;
    /** Hides the link to Business details on that tab itself. */
    onBusinessTab: boolean;
}) {
    const testForm = useForm({});
    const enabled = setting?.enabled ?? false;
    const hasProblems = Object.keys(problems).length > 0;
    const isConfigured = Boolean(
        setting?.client_id && setting?.has_client_secret,
    );
    const environment = setting?.api_environment ?? 'sandbox';
    const portalUrl =
        environment === 'production'
            ? 'https://myinvois.hasil.gov.my'
            : 'https://preprod.myinvois.hasil.gov.my';

    return (
        <div className="flex flex-col gap-6">
            <section className="flex flex-col gap-4">
                <PanelHeading
                    icon={Activity}
                    title="Status"
                    description="Whether e-invoices can go out now."
                />

                {hasProblems && (
                    <Alert variant={enabled ? 'destructive' : 'default'}>
                        <CircleAlert />
                        <AlertTitle>
                            {enabled
                                ? 'E-invoices cannot go out until these are fixed'
                                : 'Complete these before switching on'}
                        </AlertTitle>
                        <AlertDescription className="flex flex-col gap-2">
                            <ul className="flex list-disc flex-col gap-1 ps-4">
                                {Object.entries(problems).map(
                                    ([field, problem]) => (
                                        <li key={field}>{problem}</li>
                                    ),
                                )}
                            </ul>
                            {!onBusinessTab && (
                                <Link
                                    href={business()}
                                    className="font-medium underline underline-offset-4"
                                >
                                    Open Business details
                                </Link>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="flex flex-col gap-3 px-2 text-sm">
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            <StatusBadge
                                status={{
                                    value: enabled ? 'active' : 'inactive',
                                    label: !enabled
                                        ? 'Off'
                                        : hasProblems
                                          ? 'On, details missing'
                                          : 'On',
                                    tone: !enabled
                                        ? 'neutral'
                                        : hasProblems
                                          ? 'danger'
                                          : 'success',
                                }}
                            />
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">Environment</dt>
                        <dd>
                            <Badge variant="outline">
                                {ENVIRONMENT_LABEL[environment] ?? environment}
                            </Badge>
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-muted-foreground">
                            MyInvois login
                        </dt>
                        <dd>
                            <Badge
                                variant={isConfigured ? 'outline' : 'secondary'}
                            >
                                {isConfigured ? 'Saved' : 'Not set'}
                            </Badge>
                        </dd>
                    </div>
                    {setting?.effective_from && (
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-muted-foreground">On since</dt>
                            <dd className="tabular-nums">
                                {new Date(
                                    setting.effective_from,
                                ).toLocaleDateString('en-GB', {
                                    day: '2-digit',
                                    month: 'short',
                                    year: 'numeric',
                                })}
                            </dd>
                        </div>
                    )}
                </dl>

                <div className="flex flex-col gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!isConfigured || testForm.processing}
                        focusableWhenDisabled
                        onClick={() =>
                            testForm.post(testRoute.url(), {
                                preserveScroll: true,
                            })
                        }
                    >
                        {testForm.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Plug data-icon="inline-start" />
                        )}
                        Test connection
                    </Button>
                    <a
                        href={portalUrl}
                        target="_blank"
                        rel="noreferrer"
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <ExternalLink data-icon="inline-start" />
                        Open MyInvois portal
                    </a>
                </div>
            </section>

            <section className="flex flex-col gap-3">
                <PanelHeading icon={Shield} title="LHDN rules in short" />
                <ul className="flex list-disc flex-col gap-2 ps-6 text-sm text-muted-foreground">
                    <li>
                        <strong className="text-foreground">
                            72 hours to cancel.
                        </strong>{' '}
                        After that, a mistake is corrected with a credit or
                        refund note.
                    </li>
                    <li>
                        <strong className="text-foreground">
                            Monthly summary.
                        </strong>{' '}
                        Stays whose guests did not ask for an e-invoice go into
                        one summary, filed within 7 days of the month&apos;s
                        end.
                    </li>
                    <li>
                        <strong className="text-foreground">
                            Over RM10,000.
                        </strong>{' '}
                        A stay over RM10,000 needs its own e-invoice with the
                        guest&apos;s own tax number.
                    </li>
                </ul>
            </section>
        </div>
    );
}
