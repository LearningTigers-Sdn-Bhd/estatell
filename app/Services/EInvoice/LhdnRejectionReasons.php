<?php

namespace App\Services\EInvoice;

use App\Models\EInvoiceSubmission;

/**
 * Turns what LHDN said about a rejected e-invoice into plain reasons a hotel manager can act on: what is wrong, how to
 * fix it, and whose details to fix (`where`). The raw LHDN codes stay out of the page.
 *
 * `where` is buyer (the guest or company details sent), hotel (E-invoice settings), login (the MyInvois Client ID and
 * secret) or retry (nothing to fix, submit again). LHDN names the field it rejected, so a field under the supplier
 * party goes to hotel and one under the customer party goes to buyer, whatever the code.
 */
class LhdnRejectionReasons
{
    /**
     * Plain text for the LHDN codes we have seen on real submissions. Codes are matched exactly first, then by prefix.
     *
     * @var array<string, array{message: string, fix: string, where: string}>
     */
    private const array KNOWN = [
        'ERR206' => [
            'message' => "The guest's IC number does not match their tax number at LHDN.",
            'fix' => 'Check the IC and tax number with the guest, then submit again.',
            'where' => 'buyer',
        ],
        'ERR228' => [
            'message' => 'LHDN only accepts the general public tax number on a monthly summary.',
            'fix' => "Enter the guest's own tax number, or leave the stay for the monthly summary.",
            'where' => 'buyer',
        ],
        'DR308' => [
            'message' => 'The buyer is not the same as on the original e-invoice.',
            'fix' => 'Use the same buyer details as the original e-invoice.',
            'where' => 'buyer',
        ],
        'CV316' => [
            'message' => 'A Malaysian state was given for a buyer outside Malaysia.',
            'fix' => 'Set the state to Not applicable for a foreign buyer, then submit again.',
            'where' => 'buyer',
        ],
        'ERR2' => [
            'message' => "LHDN could not match the buyer's tax details.",
            'fix' => 'Check the tax number and ID with the buyer, then submit again.',
            'where' => 'buyer',
        ],
        'CV3' => [
            'message' => 'LHDN did not accept a code in the e-invoice, such as a country or state.',
            'fix' => "Check the buyer's country and state, then submit again.",
            'where' => 'buyer',
        ],
        'CF3' => [
            'message' => 'Some buyer details are missing or in the wrong format.',
            'fix' => "Check the buyer's name, address, city and phone, then submit again.",
            'where' => 'buyer',
        ],
    ];

    /**
     * The same codes when the buyer is a company, which has a registration number instead of an IC.
     *
     * @var array<string, array{message: string, fix: string, where: string}>
     */
    private const array COMPANY = [
        'ERR206' => [
            'message' => "The company's registration number does not match its tax number at LHDN.",
            'fix' => 'Check the registration and tax number with the company, then submit again.',
            'where' => 'buyer',
        ],
        'ERR2' => [
            'message' => "LHDN could not match the company's tax details.",
            'fix' => 'Check the registration and tax number with the company, then submit again.',
            'where' => 'buyer',
        ],
    ];

    private const string DEFAULT_FIX = 'Check the buyer details, then submit again.';

    /**
     * Any rejected field of the hotel's own details.
     *
     * @var array{message: string, fix: string, where: string}
     */
    private const array HOTEL = [
        'message' => "Some of your hotel's details are missing or in the wrong format.",
        'fix' => "Check the hotel's tax number, registration number, address and phone in E-invoice settings, then submit again.",
        'where' => 'hotel',
    ];

    /**
     * @return list<array{message: string, fix: string, where: string}>
     */
    public function for(EInvoiceSubmission $submission): array
    {
        $details = $submission->error_details;
        if (! is_array($details) || $details === []) {
            return [];
        }

        $isCompany = strtoupper((string) ($submission->buyer_snapshot['id_type'] ?? '')) === 'BRN';
        $reasons = [];
        foreach ($this->lhdnErrors($details) as [$code, $text, $path]) {
            $reasons[] = $this->explain($code, $text, $path, $isCompany);
        }

        if ($reasons === [] && isset($details['message'])) {
            $reasons[] = $this->connectionProblem((int) ($details['code'] ?? 0));
        }

        $reasons = array_values(array_unique($reasons, SORT_REGULAR));

        return array_map(fn (array $reason): array => ['message' => __($reason['message']), 'fix' => __($reason['fix']), 'where' => $reason['where']], $reasons);
    }

    /**
     * Whether LHDN refused the buyer's tax number (the `ERR2…` taxpayer checks, such as `ERR206` IC and tax number do not
     * match), so looking up the tax number LHDN holds for the ID can fix it.
     */
    public function concernsTaxNumber(EInvoiceSubmission $submission): bool
    {
        $details = $submission->error_details;
        if (! is_array($details)) {
            return false;
        }

        foreach ($this->lhdnErrors($details) as [$code]) {
            if (str_starts_with($code, 'ERR2')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every [code, text, field path] in the error, from the validation steps (a document LHDN accepted and then rejected) or
     * from the submission reply (a document LHDN refused on arrival).
     *
     * @param  array<string, mixed>  $details
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function lhdnErrors(array $details): array
    {
        $errors = [];

        foreach (data_get($details, 'validationResults.validationSteps', []) as $step) {
            if (($step['status'] ?? null) !== 'Invalid') {
                continue;
            }

            $inner = data_get($step, 'error.innerError');
            foreach (is_array($inner) && $inner !== [] ? $inner : [data_get($step, 'error', [])] as $error) {
                $errors[] = [(string) ($error['errorCode'] ?? ''), (string) ($error['error'] ?? ''), (string) ($error['propertyPath'] ?? '')];
            }
        }

        foreach ([data_get($details, 'details', []), data_get($details, 'rejected.error.details', [])] as $list) {
            foreach (is_array($list) ? $list : [] as $error) {
                $errors[] = [(string) ($error['code'] ?? ''), (string) ($error['message'] ?? ''), (string) ($error['propertyPath'] ?? $error['target'] ?? '')];
            }
        }

        return array_values(array_filter($errors, fn (array $error): bool => $error[0] !== '' || $error[1] !== ''));
    }

    /**
     * @return array{message: string, fix: string, where: string}
     */
    private function explain(string $code, string $text, string $path, bool $isCompany): array
    {
        if (str_contains($path, 'AccountingSupplierParty')) {
            return self::HOTEL;
        }

        $known = $isCompany ? self::COMPANY + self::KNOWN : self::KNOWN;

        if (isset($known[$code])) {
            return $known[$code];
        }

        foreach ($known as $prefix => $reason) {
            if (strlen($prefix) < 5 && $code !== '' && str_starts_with($code, $prefix)) {
                return $reason;
            }
        }

        // LHDN's own sentence ends with a link to its developer docs, which means nothing to a hotel manager.
        $message = trim((string) preg_replace('/\s*Please refer to https?:\S+/i', '', $text));

        return ['message' => $message !== '' ? $message : __('LHDN rejected the e-invoice.'), 'fix' => self::DEFAULT_FIX, 'where' => 'buyer'];
    }

    /**
     * @return array{message: string, fix: string, where: string}
     */
    private function connectionProblem(int $httpStatus): array
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return [
                'message' => "LHDN refused the hotel's MyInvois login.",
                'fix' => 'Check the Client ID and Client secret in E-invoice settings, then submit again.',
                'where' => 'login',
            ];
        }

        return [
            'message' => 'We could not reach LHDN.',
            'fix' => 'Submit again in a few minutes.',
            'where' => 'retry',
        ];
    }
}
