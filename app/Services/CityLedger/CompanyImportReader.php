<?php

namespace App\Services\CityLedger;

use App\Enums\CompanyImportMode;
use App\Enums\CompanyType;
use App\Enums\SpreadsheetFormat;
use App\Services\Guests\CountryOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads a Bulk add companies file into grid rows. Nothing is saved: staff review the rows, then create them.
 */
class CompanyImportReader
{
    /**
     * Most rows one Bulk add takes.
     */
    public const int MAX_ROWS = 200;

    public function __construct(private CountryOptions $countryOptions) {}

    /**
     * Headers match ignoring case; only `name` is required and unknown columns are ignored. Type, direct billing and
     * country are read leniently; a value that cannot be read is left empty with a problem for its field. Blank lines
     * are skipped.
     *
     * @return array{mode: string, rows: list<array<string, mixed>>}
     */
    public function read(UploadedFile $file): array
    {
        $format = SpreadsheetFormat::from(Str::lower($file->getClientOriginalExtension()));

        $reader = SimpleExcelReader::create($file->getRealPath(), $format->value)
            ->formatHeadersUsing(fn (string $header): string => Str::lower(trim($header)))
            ->trimValues();

        $headers = array_values($reader->getHeaders() ?? []);

        if (! in_array('name', $headers, true)) {
            throw ValidationException::withMessages([
                'file' => __('The first row must have a name column. Download the template to see every column.'),
            ]);
        }

        $columns = CompanyImportMode::Detailed->columns();

        $rows = $reader->getRows()
            ->map(function (array $row) use ($columns): array {
                $values = [];

                foreach ($columns as $column => $field) {
                    $values[$field] = trim((string) ($row[$column] ?? ''));
                }

                return $values;
            })
            ->reject(fn (array $values): bool => implode('', $values) === '')
            ->take(self::MAX_ROWS + 1)
            ->values();

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS]),
            ]);
        }

        $countries = [];
        foreach ($this->countryOptions->options() as $option) {
            $countries[Str::lower($option['value'])] = $option['value'];
            $countries[Str::lower($option['label'])] = $option['value'];
        }

        return [
            'mode' => CompanyImportMode::forHeaders($headers)->value,
            'rows' => array_values($rows->map(fn (array $values): array => $this->row($values, $countries))->all()),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $countries  Lower-case code or name => code.
     * @return array<string, mixed>
     */
    private function row(array $values, array $countries): array
    {
        $problems = [];

        $type = $this->type($values['type']);
        if ($type === null) {
            $problems['type'] = $values['type'] === ''
                ? __('Choose a type.')
                : __('Unknown type ":type".', ['type' => $values['type']]);
        }

        $directBill = $this->yesNo($values['direct_bill_enabled']);
        if ($directBill === null) {
            $problems['direct_bill_enabled'] = __('Write yes or no.');
        }

        $country = $countries[Str::lower($values['billing_country_code'])] ?? null;
        if ($country === null && $values['billing_country_code'] !== '') {
            $problems['billing_country_code'] = __('Unknown country ":country".', ['country' => $values['billing_country_code']]);
        }

        return [
            ...$values,
            'type' => $type->value ?? '',
            'contact_email' => Str::lower($values['contact_email']),
            'direct_bill_enabled' => $directBill ?? true,
            'billing_country_code' => $country ?? '',
            'problems' => $problems,
        ];
    }

    private function type(string $value): ?CompanyType
    {
        $key = Str::of($value)->lower()->replace([' ', '-'], '_')->toString();

        return CompanyType::tryFrom($key);
    }

    /**
     * Empty means yes, the add sheet's default.
     */
    private function yesNo(string $value): ?bool
    {
        return match (Str::lower($value)) {
            '', 'yes', 'y', 'true', '1', 'on' => true,
            'no', 'n', 'false', '0', 'off' => false,
            default => null,
        };
    }
}
