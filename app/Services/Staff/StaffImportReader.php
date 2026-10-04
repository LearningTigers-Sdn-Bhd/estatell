<?php

namespace App\Services\Staff;

use App\Enums\SpreadsheetFormat;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads a Bulk add file into grid rows. Nothing is saved: the owner reviews the rows, then creates them.
 */
class StaffImportReader
{
    /**
     * Header row of the file, in order.
     *
     * @var list<string>
     */
    public const array COLUMNS = ['name', 'email', 'role'];

    /**
     * Most rows one Bulk add takes.
     */
    public const int MAX_ROWS = 200;

    /**
     * Roles match by name, ignoring case, among the roles the owner may give. An unknown role leaves the role empty
     * with a problem for that row. Blank lines are skipped.
     *
     * @param  Collection<int, Role>  $assignableRoles
     * @return list<array{name: string, email: string, roleId: int|null, problem: string|null}>
     */
    public function read(UploadedFile $file, Collection $assignableRoles): array
    {
        $format = SpreadsheetFormat::from(Str::lower($file->getClientOriginalExtension()));

        $reader = SimpleExcelReader::create($file->getRealPath(), $format->value)
            ->formatHeadersUsing(fn (string $header): string => Str::lower(trim($header)))
            ->trimValues();

        $headers = $reader->getHeaders() ?? [];

        if (array_diff(self::COLUMNS, $headers) !== []) {
            throw ValidationException::withMessages([
                'file' => __('The first row must have the columns name, email and role.'),
            ]);
        }

        $rolesByName = $assignableRoles->keyBy(fn (Role $role): string => Str::lower($role->name));

        $rows = $reader->getRows()
            ->map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'email' => Str::lower((string) ($row['email'] ?? '')),
                'role' => (string) ($row['role'] ?? ''),
            ])
            ->reject(fn (array $row): bool => $row['name'] === '' && $row['email'] === '' && $row['role'] === '')
            ->take(self::MAX_ROWS + 1)
            ->values();

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => __('The file has more than :max rows.', ['max' => self::MAX_ROWS]),
            ]);
        }

        return $rows->map(function (array $row) use ($rolesByName): array {
            $role = $rolesByName->get(Str::lower($row['role']));

            return [
                'name' => $row['name'],
                'email' => $row['email'],
                'roleId' => $role?->id,
                'problem' => match (true) {
                    $role !== null => null,
                    $row['role'] === '' => __('Choose a role.'),
                    default => __('Unknown role ":role".', ['role' => $row['role']]),
                },
            ];
        })->all();
    }
}
