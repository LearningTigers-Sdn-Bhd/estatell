<?php

namespace App\Services\Accounting;

use App\Enums\AccountingDocumentKind;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * QNE Cloud Accounting open API (https://openapi.account.qne.cloud/doc/index.html). The API key from QNE My Apps is
 * exchanged for a short-lived token that belongs to one company. Every response is wrapped in an envelope whose `code` is
 * "0000" on success.
 */
class QneGateway implements AccountingGateway
{
    /** QNE `DocumentStatus`: 0 Draft, 1 Waiting for approval, 2 Approved. A draft lets the accountant check it first. */
    private const DRAFT = 0;

    /** QNE `DocumentStatus` 2, Approved. Invoices, credit notes and receipts must be approved to be matched to each other. */
    private const APPROVED = 2;

    private ?string $token = null;

    public function __construct(private readonly string $apiKey, private readonly string $baseUrl) {}

    public function testConnection(): void
    {
        $this->get('api/AccountCodes/Leaf/Query', ['$top' => 1]);
    }

    public function findAccountIds(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $data = $this->get('api/AccountCodes/Leaf/Query', [
                '$filter' => "code eq '".str_replace("'", "''", $code)."'",
                '$top' => 1,
            ]);

            $id = $data['value'][0]['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $ids[$code] = $id;
            }
        }

        return $ids;
    }

    public function createJournal(AccountingJournal $journal): string
    {
        $ids = $this->findAccountIds($journal->accountCodes());
        $missing = array_values(array_diff($journal->accountCodes(), array_keys($ids)));

        if ($missing !== []) {
            throw AccountingException::missingAccounts($missing);
        }

        // QNE fills the currency and the numbering of a new journal, so start from its template.
        $template = $this->get('api/Journals/New');

        $details = [];

        foreach ($journal->lines as $position => $line) {
            $details[] = [
                'pos' => $position + 1,
                // QNE ignores `accountId` on a journal line (it is read only): the account goes in as an object.
                'account' => ['id' => $ids[$line->accountCode], 'code' => $line->accountCode],
                'description' => mb_substr($line->description, 0, 250),
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                // The property's currency is QNE's local currency, so the local amounts equal the amounts.
                'debitLocal' => (float) $line->debit,
                'creditLocal' => (float) $line->credit,
            ];
        }

        $created = $this->post('api/Journals/Create', [
            ...$template,
            'documentStatus' => self::DRAFT,
            'docDate' => $journal->date->toDateString().'T00:00:00',
            'description' => mb_substr($journal->description, 0, 250),
            'referenceNo' => mb_substr($journal->reference, 0, 50),
            'details' => $details,
        ]);

        return (string) ($created['id'] ?? $created['docCode'] ?? '');
    }

    public function createCustomer(CustomerDetails $customer): string
    {
        $created = $this->post('api/Customers/Create', [
            ...$this->get('api/Customers/New'),
            'code' => mb_substr($customer->code, 0, 50),
            'name' => mb_substr($customer->name, 0, 250),
            'address1' => mb_substr((string) $customer->addressLine1, 0, 300),
            'address2' => mb_substr((string) $customer->addressLine2, 0, 300),
            'city' => mb_substr((string) $customer->city, 0, 100),
            'zipCode' => mb_substr((string) $customer->postalCode, 0, 20),
            'country' => mb_substr((string) $customer->countryCode, 0, 100),
            'phoneNo1' => mb_substr((string) $customer->phone, 0, 20),
            'emailList' => filled($customer->email) ? [$customer->email] : [],
        ]);

        return (string) ($created['id'] ?? '');
    }

    public function createInvoice(InvoiceDetails $invoice): DocumentRef
    {
        $created = $this->post('api/Invoices/Create', [
            ...$this->get('api/Invoices/New'),
            'documentStatus' => self::APPROVED,
            'customerId' => (int) $invoice->customerId,
            'docDate' => $invoice->date->toDateString().'T00:00:00',
            'dueDate' => $invoice->dueDate->toDateString().'T00:00:00',
            'referenceNo' => mb_substr($invoice->reference, 0, 50),
            'description' => mb_substr($invoice->description, 0, 250),
            'isTaxInclusive' => false,
            'details' => [$this->line($invoice->accountCode, $invoice->description, $invoice->amount)],
        ]);

        return new DocumentRef((string) ($created['id'] ?? ''), (string) ($created['docType'] ?? 'INV'));
    }

    public function createCreditNote(CreditNoteDetails $note): DocumentRef
    {
        $created = $this->post('api/ARCNs/Create', [
            ...$this->get('api/ARCNs/New'),
            'documentStatus' => self::APPROVED,
            'customerId' => (int) $note->customerId,
            'docDate' => $note->date->toDateString().'T00:00:00',
            'referenceNo' => mb_substr($note->reference, 0, 50),
            'description' => mb_substr($note->description, 0, 250),
            'isTaxInclusive' => false,
            // QNE checks the knockoff against the totals sent here, not against the lines, so the totals go in too.
            ...$this->totals((float) $note->amount),
            'details' => [$this->line($note->accountCode, $note->description, $note->amount)],
            'knockoff' => [$this->knockoff($note->invoice, $note->amount, (int) $note->customerId)],
        ]);

        return new DocumentRef((string) ($created['id'] ?? ''), (string) ($created['docType'] ?? 'ARCN'));
    }

    public function createReceipt(ReceiptDetails $receipt): DocumentRef
    {
        $bank = $this->accountId($receipt->accountCode);

        $created = $this->post('api/ARReceipts/Create', [
            ...$this->get('api/ARReceipts/New'),
            'documentStatus' => self::APPROVED,
            'customerId' => (int) $receipt->customerId,
            'docDate' => $receipt->date->toDateString().'T00:00:00',
            'referenceNo' => mb_substr($receipt->reference, 0, 50),
            'description' => mb_substr($receipt->description, 0, 250),
            'multiPayments' => [[
                'accountId' => $bank,
                'description' => mb_substr($receipt->description, 0, 250),
                'referenceNo' => mb_substr($receipt->reference, 0, 50),
                'amount' => (float) $receipt->amount,
            ]],
            'knockoff' => array_map(
                fn (ReceiptLink $link): array => $this->knockoff($link->invoice, $link->amount, (int) $receipt->customerId),
                $receipt->links,
            ),
        ]);

        return new DocumentRef((string) ($created['id'] ?? ''), (string) ($created['docType'] ?? 'OR'));
    }

    public function voidDocument(AccountingDocumentKind $kind, DocumentRef $document, string $reason): void
    {
        $path = match ($kind) {
            AccountingDocumentKind::Invoice => 'api/Invoices/Void',
            AccountingDocumentKind::CreditNote => 'api/ARCNs/Void',
            AccountingDocumentKind::Receipt => 'api/ARReceipts/Void',
        };

        $this->post($path, [
            'id' => $document->id,
            'cancelledDate' => now()->toDateString().'T00:00:00',
            'cancelledRemark' => mb_substr($reason, 0, 100),
        ]);
    }

    /**
     * The document totals QNE needs when a document is knocked off against another. The currency is QNE's local one, so
     * the local totals equal the totals.
     *
     * @return array<string, float>
     */
    private function totals(float $amount): array
    {
        return [
            'subtotalAmount' => $amount,
            'subtotalAmountLocal' => $amount,
            'totalAmount' => $amount,
            'totalAmountLocal' => $amount,
            'netTotalAmount' => $amount,
            'netTotalAmountLocal' => $amount,
        ];
    }

    /**
     * One account line with no tax.
     *
     * @return array<string, mixed>
     */
    private function line(string $accountCode, string $description, string $amount): array
    {
        return [
            'pos' => 1,
            'accountId' => $this->accountId($accountCode),
            'description' => mb_substr($description, 0, 250),
            'amount' => (float) $amount,
        ];
    }

    /**
     * An invoice matched by part of a receipt or credit note.
     *
     * @return array<string, mixed>
     */
    private function knockoff(DocumentRef $invoice, string $amount, int $customerId): array
    {
        return [
            'customerId' => $customerId,
            'docId' => $invoice->id,
            'docType' => $invoice->type ?? 'INV',
            'paymentAmount' => (float) $amount,
        ];
    }

    private function accountId(string $code): string
    {
        $id = $this->findAccountIds([$code])[$code] ?? null;

        if ($id === null) {
            throw AccountingException::missingAccounts([$code]);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->data($this->client()->get($path, $query), $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->data($this->client()->post($path, $payload), $path);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->withToken($this->token())
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
                ->acceptJson()
                ->timeout(30)
                ->get('api/Auth/Connect', ['api-key' => $this->apiKey]);
        } catch (Throwable $exception) {
            throw new AccountingException(__('QNE could not be reached.'), previous: $exception);
        }

        $token = $this->data($response, 'api/Auth/Connect')['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new AccountingException(__('QNE did not return an access token. Check the API key.'));
        }

        return $this->token = $token;
    }

    /**
     * The `data` of a successful envelope.
     *
     * @return array<string, mixed>
     */
    private function data(Response $response, string $path): array
    {
        $body = $response->json();

        if (! is_array($body) || ($body['code'] ?? null) !== '0000') {
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

            // A 400 comes as a validation problem (`errors`), not as the usual envelope with a `message`.
            if ($message === '' && is_array($body) && is_array($body['errors'] ?? null)) {
                $message = collect($body['errors'])->flatten()->implode(' ');
            }

            throw new AccountingException(__('QNE refused :path (:status). :message', [
                'path' => $path,
                'status' => $response->status(),
                'message' => $message,
            ]));
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
