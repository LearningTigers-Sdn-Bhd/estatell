import { Form, Link } from '@inertiajs/react';
import { FilePlus2, RefreshCw, Wrench } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showCompany } from '@/routes/finance/city-ledger/companies';
import { eInvoice as submitArEInvoice } from '@/routes/finance/city-ledger/invoices';
import { show } from '@/routes/finance/e-invoices';
import { edit as eInvoiceSettings } from '@/routes/settings/e-invoice';
import {
    KIND_LABEL,
    type Submission,
    folioUrl,
    isAdjustment,
    rejection,
} from '@/components/pms/einvoice/submission';

/**
 * The buttons on a rejected e-invoice, each to the page that holds the details LHDN rejected. The one for the main
 * problem is the primary button. A monthly summary has its own Submit the month again.
 */
export function RejectedActions({ submission }: { submission: Submission }) {
    const needs = rejection(submission);
    const settingsLink = needs.settings && (
        <Link
            href={eInvoiceSettings()}
            className={buttonVariants({
                variant: needs.buyer ? 'outline' : 'default',
            })}
        >
            <Wrench data-icon="inline-start" />
            E-invoice settings
        </Link>
    );

    if (isAdjustment(submission)) {
        return (
            <>
                {submission.original && (
                    <Link
                        href={show.url(
                            { submission: submission.original.id },
                            { query: { credit_note: 'new' } },
                        )}
                        className={buttonVariants()}
                    >
                        <FilePlus2 data-icon="inline-start" />
                        Issue a new {KIND_LABEL[submission.kind].toLowerCase()}
                    </Link>
                )}
                {settingsLink}
            </>
        );
    }

    if (!submission.can_resubmit) {
        return settingsLink || null;
    }

    if (submission.ar_invoice) {
        return (
            <>
                {needs.buyer && (
                    <Link
                        href={showCompany({
                            company: submission.ar_invoice.company_id,
                        })}
                        className={buttonVariants()}
                    >
                        <Wrench data-icon="inline-start" />
                        Edit company details
                    </Link>
                )}
                {settingsLink}
                <Form
                    {...submitArEInvoice.form({
                        invoice: submission.ar_invoice.id,
                    })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <RefreshCw data-icon="inline-start" />
                            )}
                            Submit again
                        </Button>
                    )}
                </Form>
            </>
        );
    }

    const folioFormUrl = folioUrl(submission, true);

    return (
        <>
            {folioFormUrl && (
                <Link
                    href={folioFormUrl}
                    className={buttonVariants({
                        variant: needs.buyer ? 'default' : 'outline',
                    })}
                >
                    {needs.buyer ? (
                        <Wrench data-icon="inline-start" />
                    ) : (
                        <RefreshCw data-icon="inline-start" />
                    )}
                    {needs.buyer ? 'Fix and submit again' : 'Submit again'}
                </Link>
            )}
            {settingsLink}
        </>
    );
}
