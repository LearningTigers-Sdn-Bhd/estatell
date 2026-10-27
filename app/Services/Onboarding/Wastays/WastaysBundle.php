<?php

namespace App\Services\Onboarding\Wastays;

use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use JsonException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * An extracted wastays export bundle: a folder with manifest.json and one CSV per table.
 */
final class WastaysBundle
{
    private ?string $timeZone = null;

    private function __construct(
        public readonly string $path,
        public readonly WastaysManifest $manifest,
    ) {}

    /** @throws InvalidArgumentException when the folder has no readable, supported manifest */
    public static function open(string $path): self
    {
        $file = rtrim($path, '/').'/manifest.json';

        if (! is_file($file)) {
            throw new InvalidArgumentException('The bundle has no manifest.json.');
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The manifest.json is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('The manifest.json is not valid JSON.');
        }

        return new self(rtrim($path, '/'), WastaysManifest::fromArray($data));
    }

    /**
     * Data rows of one table as associative arrays. Empty values are null.
     *
     * @return LazyCollection<int, array<string, string|null>>
     */
    public function rows(string $table): LazyCollection
    {
        $file = $this->csvPath($table);

        return LazyCollection::make(function () use ($file) {
            $reader = SimpleExcelReader::create($file)->trimHeaderRow();

            foreach ($reader->getRows() as $row) {
                yield array_map(fn ($value) => $value === '' ? null : $value, $row);
            }

            $reader->close();
        });
    }

    /**
     * The calendar date a wastays timestamp falls on in the hotel's own time zone. wastays keeps `check_in` and
     * `check_out` as UTC timestamps, so a Malaysian check-in at 03:00 is stored on the evening before. A plain date or
     * an empty value comes back as it is.
     */
    public function localDate(string $value): string
    {
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone($this->timeZone())->toDateString();
    }

    /**
     * The hotel's time zone from `hotels.csv` (wastays names it like "Kuala Lumpur"), Kuala Lumpur when the file is not in the bundle.
     */
    public function timeZone(): string
    {
        if ($this->timeZone !== null) {
            return $this->timeZone;
        }

        $name = array_key_exists('hotels.csv', $this->manifest->files) ? trim((string) ($this->rows('hotels')->first()['time_zone'] ?? '')) : '';

        foreach ([$name, 'Asia/'.str_replace(' ', '_', $name), 'Pacific/'.str_replace(' ', '_', $name), 'Australia/'.str_replace(' ', '_', $name)] as $candidate) {
            if ($name !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
                return $this->timeZone = $candidate;
            }
        }

        return $this->timeZone = 'Asia/Kuala_Lumpur';
    }

    /**
     * Compare the folder with the manifest.
     *
     * @return list<string> one sentence per mismatch, empty when the bundle is good
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->manifest->files as $name => $expected) {
            $file = $this->path.'/'.$name;

            if (! is_file($file)) {
                $problems[] = "{$name} is listed in the manifest but missing.";

                continue;
            }

            if (hash_file('sha256', $file) !== $expected['sha256']) {
                $problems[] = "{$name} has a different SHA-256 than the manifest.";
            }

            $rows = $this->rows(basename($name, '.csv'))->count();
            if ($rows !== $expected['rows']) {
                $problems[] = "{$name} has {$rows} rows, the manifest says {$expected['rows']}.";
            }
        }

        foreach (glob($this->path.'/*.csv') ?: [] as $file) {
            if (! array_key_exists(basename($file), $this->manifest->files)) {
                $problems[] = basename($file).' is in the bundle but not in the manifest.';
            }
        }

        return $problems;
    }

    private function csvPath(string $table): string
    {
        $name = $table.'.csv';

        if (! array_key_exists($name, $this->manifest->files) || ! is_file($this->path.'/'.$name)) {
            throw new InvalidArgumentException("The bundle has no {$name}.");
        }

        return $this->path.'/'.$name;
    }
}
