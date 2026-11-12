import { Head } from '@inertiajs/react';
import { FileDown } from 'lucide-react';
import { VoucherDetail } from '@/components/pms/commissions/voucher-detail';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { index, pdf, show } from '@/routes/finance/payouts/vouchers';

type Props = { voucher: App.Data.Commissions.PayoutVoucherData };

/**
 * One payout voucher, read-only: its lines, totals and the bank account Estatell pays.
 * See docs/30-commission-fees/06-property-payouts.md#payouts-tab.
 */
export default function PropertyPayoutVoucherShow({ voucher: detail }: Props) {
    const { voucher } = detail;

    return (
        <>
            <Head title={voucher.number} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={voucher.number}
                    back={{ href: index(), label: 'payouts' }}
                    badge={<StatusBadge status={voucher.status} />}
                    actions={
                        <a
                            href={pdf.url({ payoutVoucher: voucher.id })}
                            target="_blank"
                            rel="noreferrer"
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <FileDown aria-hidden />
                            PDF
                        </a>
                    }
                />

                <VoucherDetail detail={detail} />
            </div>
        </>
    );
}

PropertyPayoutVoucherShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Payouts', href: index() },
        {
            title: props.voucher.voucher.number,
            href: show({ payoutVoucher: props.voucher.voucher.id }),
        },
    ],
});
