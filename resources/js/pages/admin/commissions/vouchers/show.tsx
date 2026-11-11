import { Head } from '@inertiajs/react';
import { Check, FileDown, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    AdjustmentDialog,
    ApproveVoucherDialog,
    MarkPaidDialog,
} from '@/components/pms/commissions/voucher-dialogs';
import { VoucherDetail } from '@/components/pms/commissions/voucher-detail';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index as commissions } from '@/routes/admin/commissions';
import { index, pdf, show } from '@/routes/admin/commissions/vouchers';

type Props = { voucher: App.Data.Commissions.PayoutVoucherData };

/**
 * One payout voucher: its lines, totals and the bank account to pay, with the actions its status allows.
 * See docs/30-commission-fees/05-platform-pages.md#voucher-page.
 */
export default function PayoutVoucherShow({ voucher: detail }: Props) {
    const { voucher } = detail;
    const [dialog, setDialog] = useState<'approve' | 'paid' | 'adjust' | null>(
        null,
    );
    const close = (open: boolean) => !open && setDialog(null);
    const noBankAccount =
        detail.bankAccount === null && !voucher.owedToEstatell;

    return (
        <>
            <Head title={voucher.number} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={voucher.number}
                    description={voucher.propertyName}
                    back={{ href: index(), label: 'payout vouchers' }}
                    badge={<StatusBadge status={voucher.status} />}
                    actions={
                        <>
                            {detail.canAdjust && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('adjust')}
                                >
                                    <Plus aria-hidden />
                                    Add adjustment
                                </Button>
                            )}
                            {detail.canApprove && (
                                <Button onClick={() => setDialog('approve')}>
                                    <Check aria-hidden />
                                    Approve
                                </Button>
                            )}
                            {detail.canMarkPaid && (
                                <Button onClick={() => setDialog('paid')}>
                                    <Check aria-hidden />
                                    Mark as paid
                                </Button>
                            )}
                            <a
                                href={pdf.url(voucher.id)}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <FileDown aria-hidden />
                                PDF
                            </a>
                        </>
                    }
                />

                {noBankAccount && voucher.status.value === 'draft' && (
                    <Alert>
                        <AlertDescription>
                            {voucher.propertyName} has no active bank account.
                            Ask the property to add one under Settings before
                            you approve this voucher.
                        </AlertDescription>
                    </Alert>
                )}

                <VoucherDetail detail={detail} />
            </div>

            <ApproveVoucherDialog
                open={dialog === 'approve'}
                onOpenChange={close}
                voucher={voucher}
            />
            <MarkPaidDialog
                open={dialog === 'paid'}
                onOpenChange={close}
                voucher={voucher}
            />
            <AdjustmentDialog
                open={dialog === 'adjust'}
                onOpenChange={close}
                voucher={voucher}
            />
        </>
    );
}

PayoutVoucherShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Commissions', href: commissions() },
        { title: 'Vouchers', href: index() },
        {
            title: props.voucher.voucher.number,
            href: show(props.voucher.voucher.id),
        },
    ],
});
