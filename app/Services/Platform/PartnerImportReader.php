<?php

namespace App\Services\Platform;

use App\Enums\SpreadsheetFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads a partner Bulk add file into grid rows. Nothing is saved: the admin reviews the rows, then creates them.
 */
class PartnerImportReader
{
    /**
     * Header row of the file, in order, after headings are normalised.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['partner_name', 'prefix', 'user_name', 'email'];

    /**
     * Most rows one Bulk add takes.
     */
    public const int MAX_ROWS = 200;

    /**
     * Headings match ignoring case, spaces and underscores, so "Partner name" works. Blank lines are skipped. A blank
     * prefix stays blank here; the browser suggests one from the partner name.
     *
     * @return list<array{partner_name: string, code_prefix: string, name: string, email: string}>
     */
    public function read(UploadedFile $file): array
    {
        $format = SpreadsheetFormat::from(Str::lower($file->getClientOriginalExtension()));

        $reader = SimpleExcelReader::create($file->getRealPath(), $format->value)
            ->formatHeadersUsing(fn (string $header): string => Str::snake(Str::lower(trim($header))))
            ->trimValues();

        $headers = $reader->getHeaders() ?? [];

        if (array_diff(self::COLUMNS, $headers) !== []) {
            throw ValidationException::withMessages([
                'file' => __('The first row must have the columns partner name, prefix, user name and email.'),
            ]);
        }

        $rows = $reader->getRows()
            ->map(fn (array $row): array => [
                'partner_name' => (string) ($row['partner_name'] ?? ''),
                'code_prefix' => Str::upper((string) ($row['prefix'] ?? '')),
                'name' => (string) ($row['user_name'] ?? ''),
                'email' => Str::lower((string) ($row['email'] ?? '')),
            ])
            ->reject(fn (array $row): bool => $row['partner_name'] === '' && $row['code_prefix'] === '' && $row['name'] === '' && $row['email'] === '')
            ->take(self::MAX_ROWS + 1)
            ->values();

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS]),
            ]);
        }

        return array_values($rows->all());
    }
}
