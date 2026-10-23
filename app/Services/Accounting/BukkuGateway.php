<?php

namespace App\Services\Accounting;

use App\Enums\AccountingDocumentKind;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bukku API (https://developers.bukku.my/). The access token comes from Control Panel > Integrations and the company is
 * named by the `Company-Subdomain` header. Bukku advises one consolidated entry per day instead of one per
 * transaction, which is what the daily journal is. The limit is 600 requests per minute.
 */
class BukkuGateway implements AccountingGateway
{
    /** Bukku transaction status: draft, pending_approval, ready, void. A draft lets the accountant check it first. */
    private const DRAFT = 'draft';

    /** Bukku `ready` is a final transaction. Invoices, credit notes and payments must be ready to be matched to each other. */
    private const READY = 'ready';

    /** @var array<string, string>|null */
    private ?array $accounts = null;

    public function __construct(
        private readonly string $token,
        private readonly string $subdomain,
        private readonly string $baseUrl,
    ) {}

    public function testConnection(): void
    {
        $this->accounts();
    }

    public function findAccountIds(array $codes): array
    {
        return array_intersect_key($this->accounts(), array_flip($codes));
    }

    public function createJournal(AccountingJournal $journal): string
    {
        $ids = $this->findAccountIds($journal->accountCodes());
        $missing = array_values(array_diff($journal->accountCodes(), array_keys($ids)));

        if ($missing !== []) {
            throw AccountingException::missingAccounts($missing);
        }

        $items = [];

        foreach ($journal->lines as $position => $line) {
            $items[] = [
                'line' => $position + 1,
                'account_id' => (int) $ids[$line->accountCode],
                'description' => mb_substr($line->description, 0, 255),
                'debit_amount' => (float) $line->debit > 0 ? (float) $line->debit : null,
                'credit_amount' => (float) $line->credit > 0 ? (float) $line->credit : null,
            ];
        }

        $response = $this->send('POST', 'journal_entries', [
            'date' => $journal->date->toDateString(),
            'currency_code' => $journal->currency,
            'exchange_rate' => 1,
            'description' => mb_substr($journal->description, 0, 255),
            'number2' => mb_substr($journal->reference, 0, 50),
            'status' => self::DRAFT,
            'journal_items' => $items,
        ]);

        return (string) ($response['transaction']['id'] ?? '');
    }

    public function createCustomer(CustomerDetails $customer): string
    {
        $created = $this->send('POST', 'contacts', [
            'entity_type' => $customer->international ? 'FOREIGN_COMPANY' : 'MALAYSIAN_COMPANY',
            'legal_name' => mb_substr($customer->name, 0, 255),
            'contact_code' => mb_substr($customer->code, 0, 12),
            'types' => ['customer'],
            'email' => $customer->email,
            'phone_no' => $customer->phone,
        ]);

        return (string) ($created['contact']['id'] ?? '');
    }

    public function createInvoice(InvoiceDetails $invoice): DocumentRef
    {
        $created = $this->send('POST', 'sales/invoices', [
            'payment_mode' => 'credit',
            'contact_id' => (int) $invoice->customerId,
            'date' => $invoice->date->toDateString(),
            'currency_code' => $invoice->currency,
            'exchange_rate' => 1,
            'number2' => mb_substr($invoice->reference, 0, 50),
            'description' => mb_substr($invoice->description, 0, 255),
            'tax_mode' => 'exclusive',
            'form_items' => [$this->item($invoice->accountCode, $invoice->description, $invoice->amount)],
            'term_items' => [['date' => $invoice->dueDate->toDateString(), 'payment_due' => '100%']],
            'status' => self::READY,
        ]);

        return new DocumentRef((string) ($created['transaction']['id'] ?? ''));
    }

    public function createCreditNote(CreditNoteDetails $note): DocumentRef
    {
        $created = $this->send('POST', 'sales/credit_notes', [
            'contact_id' => (int) $note->customerId,
            'date' => $note->date->toDateString(),
            'currency_code' => $note->currency,
            'exchange_rate' => 1,
            'number2' => mb_substr($note->reference, 0, 50),
            'description' => mb_substr($note->description, 0, 255),
            'tax_mode' => 'exclusive',
            'form_items' => [$this->item($note->accountCode, $note->description, $note->amount)],
            'link_items' => [['target_transaction_id' => (int) $note->invoice->id, 'apply_amount' => (float) $note->amount]],
            'status' => self::READY,
        ]);

        return new DocumentRef((string) ($created['transaction']['id'] ?? ''));
    }

    public function createReceipt(ReceiptDetails $receipt): DocumentRef
    {
        $bank = $this->findAccountIds([$receipt->accountCode])[$receipt->accountCode] ?? null;

        if ($bank === null) {
            throw AccountingException::missingAccounts([$receipt->accountCode]);
        }

        $created = $this->send('POST', 'sales/payments', [
            'contact_id' => (int) $receipt->customerId,
            'date' => $receipt->date->toDateString(),
            'currency_code' => $receipt->currency,
            'exchange_rate' => 1,
            'amount' => (float) $receipt->amount,
            'number2' => mb_substr($receipt->reference, 0, 50),
            'description' => mb_substr($receipt->description, 0, 255),
            'link_items' => array_map(
                fn (ReceiptLink $link): array => ['target_transaction_id' => (int) $link->invoice->id, 'apply_amount' => (float) $link->amount],
                $receipt->links,
            ),
            'deposit_items' => [['account_id' => (int) $bank, 'amount' => (float) $receipt->amount, 'number' => mb_substr($receipt->reference, 0, 50)]],
            'status' => self::READY,
        ]);

        return new DocumentRef((string) ($created['transaction']['id'] ?? ''));
    }

    public function voidDocument(AccountingDocumentKind $kind, DocumentRef $document, string $reason): void
    {
        $path = match ($kind) {
            AccountingDocumentKind::Invoice => 'sales/invoices',
            AccountingDocumentKind::CreditNote => 'sales/credit_notes',
            AccountingDocumentKind::Receipt => 'sales/payments',
        };

        $this->send('PATCH', "{$path}/{$document->id}", ['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * One line that posts to an account, with no product and no tax.
     *
     * @return array<string, mixed>
     */
    private function item(string $accountCode, string $description, string $amount): array
    {
        $id = $this->findAccountIds([$accountCode])[$accountCode] ?? null;

        if ($id === null) {
            throw AccountingException::missingAccounts([$accountCode]);
        }

        return [
            'account_id' => (int) $id,
            'description' => mb_substr($description, 0, 255),
            'unit_price' => (float) $amount,
            'quantity' => 1,
        ];
    }

    /**
     * Bukku account ids by account code, read once.
     *
     * @return array<string, string>
     */
    private function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $accounts = [];

        foreach ($this->send('GET', 'accounts')['accounts'] ?? [] as $account) {
            if (is_array($account) && isset($account['id'], $account['code'])) {
                $accounts[(string) $account['code']] = (string) $account['id'];
            }
        }

        return $this->accounts = $accounts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        if (blank($this->token) || blank($this->subdomain)) {
            throw new AccountingException(__('Enter the Bukku access token and company subdomain first.'));
        }

        try {
            $response = $this->client()->send($method, $path, $method === 'GET' ? [] : ['json' => $payload]);
        } catch (Throwable $exception) {
            throw new AccountingException(__('Bukku could not be reached.'), previous: $exception);
        }

        return $this->body($response, $path);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->withToken($this->token)
            ->withHeaders(['Company-Subdomain' => $this->subdomain])
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response, string $path): array
    {
        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('error') ?? '';

            throw new AccountingException(__('Bukku refused :path (:status). :message', [
                'path' => $path,
                'status' => $response->status(),
                'message' => is_string($message) ? $message : json_encode($message),
            ]));
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
