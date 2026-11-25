import { Head } from '@inertiajs/react';
import {
    PosReceiptClaimConfirmForm,
    PosReceiptClaimDetailsForm,
} from '@/components/pms/pos/pos-receipt-claim-forms';

type Props = {
    token: string;
    property: App.Data.Property.PropertySummaryData | null;
    outletName: string;
    step: 'confirm' | 'details' | 'closed';
    claimed: boolean;
};

export default function ReceiptClaim({
    token,
    outletName,
    step,
    claimed,
}: Props) {
    return (
        <>
            <Head title="Get your e-invoice" />
            <div className="mx-auto flex w-full max-w-md flex-col gap-6 p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="font-heading text-xl font-semibold">
                        Get your e-invoice
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {outletName}
                    </p>
                </div>
                {claimed ? (
                    <p role="status">
                        Your e-invoice was sent to LHDN MyInvois. Thank you.
                    </p>
                ) : step === 'closed' ? (
                    <p role="status">
                        This receipt can no longer be claimed here. Please ask
                        the staff for help.
                    </p>
                ) : step === 'details' ? (
                    <PosReceiptClaimDetailsForm token={token} />
                ) : (
                    <PosReceiptClaimConfirmForm token={token} />
                )}
            </div>
        </>
    );
}
