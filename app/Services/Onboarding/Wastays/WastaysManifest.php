<?php

namespace App\Services\Onboarding\Wastays;

use InvalidArgumentException;

/**
 * The manifest.json of a wastays export bundle.
 */
final readonly class WastaysManifest
{
    public const SUPPORTED_FORMAT_VERSION = 1;

    /**
     * @param  array<string, array{rows: int, sha256: string}>  $files  keyed by file name, such as bookings.csv
     */
    public function __construct(
        public int $hotelId,
        public string $hotelCode,
        public string $hotelName,
        public string $exportedAt,
        public string $firstAuditDate,
        public string $lastAuditDate,
        public array $files,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        if (($data['format_version'] ?? null) !== self::SUPPORTED_FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported manifest format_version. Expected '.self::SUPPORTED_FORMAT_VERSION.'.');
        }

        foreach (['hotel_id', 'hotel_code', 'hotel_name', 'exported_at', 'first_audit_date', 'last_audit_date', 'files'] as $key) {
            if (! isset($data[$key])) {
                throw new InvalidArgumentException("The manifest has no {$key}.");
            }
        }

        $files = [];
        foreach ((array) $data['files'] as $name => $entry) {
            if (! is_array($entry) || ! isset($entry['rows'], $entry['sha256'])) {
                throw new InvalidArgumentException("The manifest entry for {$name} needs rows and sha256.");
            }
            $files[(string) $name] = ['rows' => (int) $entry['rows'], 'sha256' => strtolower((string) $entry['sha256'])];
        }

        return new self(
            hotelId: (int) $data['hotel_id'],
            hotelCode: (string) $data['hotel_code'],
            hotelName: (string) $data['hotel_name'],
            exportedAt: (string) $data['exported_at'],
            firstAuditDate: (string) $data['first_audit_date'],
            lastAuditDate: (string) $data['last_audit_date'],
            files: $files,
        );
    }
}
