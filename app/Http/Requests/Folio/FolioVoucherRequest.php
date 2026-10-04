<?php

namespace App\Http\Requests\Folio;

use App\Services\Folio\FolioVoucher;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FolioVoucherRequest extends FormRequest
{
    /**
     * The `can:folio.print-voucher` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the shape is checked here. The controller checks that every row belongs to the folio and can be printed.
     * `lines` are posted line ids and `scheduled` are Scheduled row keys such as `night:12` or `addon:3:tax:1`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:'.FolioVoucher::MAX_LINES],
            'lines.*' => ['integer', 'distinct'],
            'scheduled' => ['nullable', 'array', 'max:'.FolioVoucher::MAX_LINES],
            'scheduled.*' => ['string', 'max:80', 'regex:/^[A-Za-z0-9:_-]+$/', 'distinct'],
        ];
    }

    /**
     * At least one row, and no more than the cap in all.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $count = count($this->lineIds()) + count($this->scheduledKeys());

                if ($count === 0) {
                    $validator->errors()->add('lines', __('Choose at least one line.'));
                } elseif ($count > FolioVoucher::MAX_LINES) {
                    $validator->errors()->add('lines', __('Choose up to :max lines.', ['max' => FolioVoucher::MAX_LINES]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.max' => __('Choose up to :max lines.', ['max' => FolioVoucher::MAX_LINES]),
            'scheduled.max' => __('Choose up to :max lines.', ['max' => FolioVoucher::MAX_LINES]),
        ];
    }

    /**
     * @return list<int>
     */
    public function lineIds(): array
    {
        return array_map(intval(...), $this->array('lines'));
    }

    /**
     * @return list<string>
     */
    public function scheduledKeys(): array
    {
        return array_values(array_map(strval(...), $this->array('scheduled')));
    }
}
