<?php

namespace App\Services\EInvoice;

use App\Models\EInvoiceSubmission;

/**
 * Turns what LHDN said about a rejected e-invoice into plain reasons a hotel manager can act on: what is wrong and
 * how to fix it. The raw LHDN codes stay out of the page.
 */
class LhdnRejectionReasons
{
    /**
     * Plain text for the LHDN codes we have seen on real submissions. Codes are matched exactly first, then by prefix.
     *
     * @var array<string, array{message: string, fix: string}>
     */
    private const array KNOWN = [
        'ERR206' => [
            'message' => "The guest's IC number does not match their tax number at LHDN.",
            'fix' => 'Check the IC and tax number with the guest, then submit again.',
        ],
        'ERR228' => [
            'message' => 'LHDN only accepts the general public tax number on a monthly summary.',
            'fix' => "Enter the guest's own tax number, or leave the stay for the monthly summary.",
        ],
        'DR308' => [
            'message' => 'The buyer is not the same as on the original e-invoice.',
            'fix' => 'Use the same buyer details as the original e-invoice.',
        ],
        'CV316' => [
            'message' => 'A Malaysian state was given for a buyer outside Malaysia.',
            'fix' => 'Set the state to Not applicable for a foreign buyer, then submit again.',
        ],
        'ERR2' => [
            'message' => "LHDN could not match the buyer's tax details.",
            'fix' => 'Check the tax number and ID with the buyer, then submit again.',
        ],
        'CV3' => [
            'message' => 'LHDN did not accept a code in the e-invoice, such as a country or state.',
            'fix' => "Check the buyer's country and state, then submit again.",
        ],
        'CF3' => [
            'message' => 'Some buyer or hotel details are missing or in the wrong format.',
            'fix' => "Check the buyer's name, address, city and phone, and the hotel details in E-invoice settings.",
        ],
    ];

    private const string DEFAULT_FIX = 'Check the buyer details, then submit again.';

    /**
     * @return list<array{message: string, fix: string}>
     */
    public function for(EInvoiceSubmission $submission): array
    {
        $details = $submission->error_details;
        if (! is_array($details) || $details === []) {
            return [];
        }

        $reasons = [];
        foreach ($this->lhdnErrors($details) as [$code, $text]) {
            $reasons[] = $this->explain($code, $text);
        }

        if ($reasons === [] && isset($details['message'])) {
            $reasons[] = $this->connectionProblem((int) ($details['code'] ?? 0));
        }

        $reasons = array_values(array_unique($reasons, SORT_REGULAR));

        return array_map(fn (array $reason): array => ['message' => __($reason['message']), 'fix' => __($reason['fix'])], $reasons);
    }

    /**
     * Every [code, text] pair in the error, from the validation steps (a document LHDN accepted and then rejected) or
     * from the submission reply (a document LHDN refused on arrival).
     *
     * @param  array<string, mixed>  $details
     * @return list<array{0: string, 1: string}>
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
                $errors[] = [(string) ($error['errorCode'] ?? ''), (string) ($error['error'] ?? '')];
            }
        }

        foreach ([data_get($details, 'details', []), data_get($details, 'rejected.error.details', [])] as $list) {
            foreach (is_array($list) ? $list : [] as $error) {
                $errors[] = [(string) ($error['code'] ?? ''), (string) ($error['message'] ?? '')];
            }
        }

        return array_values(array_filter($errors, fn (array $error): bool => $error[0] !== '' || $error[1] !== ''));
    }

    /**
     * @return array{message: string, fix: string}
     */
    private function explain(string $code, string $text): array
    {
        if (isset(self::KNOWN[$code])) {
            return self::KNOWN[$code];
        }

        foreach (self::KNOWN as $prefix => $reason) {
            if (strlen($prefix) < 5 && $code !== '' && str_starts_with($code, $prefix)) {
                return $reason;
            }
        }

        // LHDN's own sentence ends with a link to its developer docs, which means nothing to a hotel manager.
        $message = trim((string) preg_replace('/\s*Please refer to https?:\S+/i', '', $text));

        return ['message' => $message !== '' ? $message : __('LHDN rejected the e-invoice.'), 'fix' => self::DEFAULT_FIX];
    }

    /**
     * @return array{message: string, fix: string}
     */
    private function connectionProblem(int $httpStatus): array
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return [
                'message' => "LHDN refused the hotel's MyInvois login.",
                'fix' => 'Check the Client ID and Client secret in E-invoice settings, then submit again.',
            ];
        }

        return [
            'message' => 'We could not reach LHDN.',
            'fix' => 'Submit again in a few minutes.',
        ];
    }
}
