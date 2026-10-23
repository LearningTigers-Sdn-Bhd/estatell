<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The account codes of Settings → Accounting, keyed by transaction code id. The route's `can:` middleware checks
 * `settings.manage`. Ids of another property are ignored by the Action.
 */
class SaveAccountMappingsRequest extends FormRequest
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
            'accounts' => ['present', 'array'],
            'accounts.*' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<int, string|null>
     */
    public function accounts(): array
    {
        /** @var array<int|string, string|null> $accounts */
        $accounts = $this->validated('accounts');

        return collect($accounts)->mapWithKeys(fn (?string $account, int|string $id): array => [(int) $id => $account])->all();
    }
}
