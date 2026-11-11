<?php

namespace App\Actions\Portal;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadCompanyPaymentSlip
{
    /**
     * Save a slip a company on credit terms sends for its account, with the file on the private media disk. It is not a
     * payment until staff accept it. `$bankAccountId` is Paid into, checked by the request. `$arInvoiceId` is the invoice
     * of the booking the company paid for, which staff fill first. Logs `payment_slip.uploaded` on the company.
     * See docs/15-city-ledger/03-ar-payments.md#payment-slips.
     */
    public function __invoke(User $actor, Company $company, UploadedFile $file, string $amount, ?string $reference, ?int $bankAccountId = null, ?int $arInvoiceId = null): PaymentProof
    {
        return DB::transaction(function () use ($actor, $company, $file, $amount, $reference, $bankAccountId, $arInvoiceId): PaymentProof {
            $slip = new PaymentProof(['amount' => $amount, 'reference' => filled($reference) ? trim((string) $reference) : null, 'bank_account_id' => $bankAccountId]);
            $slip->forceFill(['property_id' => $company->property_id, 'company_id' => $company->id, 'ar_invoice_id' => $arInvoiceId, 'uploaded_by' => $actor->id])->save();
            $slip->addMedia($file)->toMediaCollection(PaymentProof::FILE);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('payment_slip.uploaded')
                ->withProperties(['property_id' => $company->property_id, 'payment_proof_id' => $slip->id, 'amount' => $slip->amount, 'reference' => $slip->reference, 'ar_invoice_id' => $arInvoiceId])
                ->log('Payment slip uploaded');

            return $slip;
        });
    }
}
