<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountingProvider;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The connection form of Settings → Accounting. The route's `can:` middleware checks `settings.manage`. A blank API key
 * keeps the saved one (unless the provider changed), but turning the connection on needs a key, the guest ledger account
 * and, for a provider that names the company by subdomain, the subdomain.
 */
class SaveAccountingConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(AccountingProvider::class)],
            'api_key' => ['nullable', 'string', 'max:255'],
            'company_subdomain' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/i'],
            'sandbox' => ['sometimes', 'boolean'],
            'guest_ledger_account_code' => ['nullable', 'string', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
            'push_company_billing' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('push_company_billing') && ! $this->boolean('enabled')) {
                $validator->errors()->add('push_company_billing', __('Turn on Push closed days automatically first: company invoices need the daily journal.'));
            }

            if (! $this->boolean('enabled')) {
                return;
            }

            $property = $this->route('property');
            $saved = $property instanceof Property ? $property->accountingConnection : null;
            $provider = AccountingProvider::from((string) $this->input('provider'));
            $hasKey = filled($this->input('api_key')) || ($saved?->provider === $provider && filled($saved->api_key));

            if (! $hasKey) {
                $validator->errors()->add('api_key', __('Enter the API key before turning this on.'));
            }

            if ($provider->needsCompanySubdomain() && blank($this->input('company_subdomain'))) {
                $validator->errors()->add('company_subdomain', __('Enter the company subdomain before turning this on.'));
            }

            if (blank($this->input('guest_ledger_account_code'))) {
                $validator->errors()->add('guest_ledger_account_code', __('Enter the guest ledger account before turning this on.'));
            }
        });
    }

    /**
     * @return array{provider: string, api_key: string|null, company_subdomain: string|null, sandbox: bool, guest_ledger_account_code: string|null, enabled: bool, push_company_billing: bool}
     */
    public function connection(): array
    {
        return [
            'provider' => (string) $this->input('provider'),
            'api_key' => $this->filled('api_key') ? (string) $this->input('api_key') : null,
            'company_subdomain' => $this->filled('company_subdomain') ? (string) $this->input('company_subdomain') : null,
            'sandbox' => $this->boolean('sandbox'),
            'guest_ledger_account_code' => $this->filled('guest_ledger_account_code') ? (string) $this->input('guest_ledger_account_code') : null,
            'enabled' => $this->boolean('enabled'),
            'push_company_billing' => $this->boolean('push_company_billing'),
        ];
    }
}
