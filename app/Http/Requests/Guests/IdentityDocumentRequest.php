<?php

namespace App\Http\Requests\Guests;

use App\Enums\IdentityDocumentType;
use App\Models\GuestIdentityDocument;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class IdentityDocumentRequest extends FormRequest
{
    /**
     * Scan size limit, in kilobytes.
     */
    public const int MAX_SCAN_KILOBYTES = 10240;

    /**
     * The `can:guests.view-documents` and `can:guests.manage` route middleware authorize this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Staff may type a number with spaces or dashes. It is validated and saved in its normalized form.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('number'))) {
            $this->merge(['number' => app(IdentityNumber::class)->normalize($this->input('number'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $scan = ['nullable', 'file', 'mimetypes:'.implode(',', GuestIdentityDocument::SCAN_MIME_TYPES), 'max:'.self::MAX_SCAN_KILOBYTES];

        return [
            'type' => ['required', Rule::enum(IdentityDocumentType::class)],
            'number' => ['required', 'string', 'min:4', 'max:40', Rule::when($this->input('type') === IdentityDocumentType::MyKad->value, ['digits:12'])],
            'issuing_country_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'front_scan' => $scan,
            'back_scan' => $scan,
            'remove_front_scan' => ['nullable', 'boolean'],
            'remove_back_scan' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{type: string, number: string, issuing_country_code: string|null, expires_on: string|null}
     */
    public function documentAttributes(): array
    {
        /** @var array{type: string, number: string, issuing_country_code: string|null, expires_on: string|null} */
        return $this->safe()->only(['type', 'number', 'issuing_country_code', 'expires_on']);
    }

    public function frontScan(): ?UploadedFile
    {
        return $this->file('front_scan');
    }

    public function backScan(): ?UploadedFile
    {
        return $this->file('back_scan');
    }

    /**
     * @return list<string>
     */
    public function removeScans(): array
    {
        return array_values(array_filter([
            $this->boolean('remove_front_scan') ? GuestIdentityDocument::FRONT_SCAN : null,
            $this->boolean('remove_back_scan') ? GuestIdentityDocument::BACK_SCAN : null,
        ]));
    }
}
