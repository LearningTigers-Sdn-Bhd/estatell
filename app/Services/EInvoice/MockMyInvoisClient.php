<?php

namespace App\Services\EInvoice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class MockMyInvoisClient
{
    /** @var (callable(list<array<string, mixed>>): array<string, mixed>)|null */
    public static $submitDocumentsOverride = null;

    /** @var (callable(string): array<string, mixed>)|null */
    public static $documentDetailsOverride = null;

    public static function resetOverrides(): void
    {
        self::$submitDocumentsOverride = null;
        self::$documentDetailsOverride = null;
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    public function submitDocuments(array $documents): array
    {
        if (self::$submitDocumentsOverride !== null) {
            return (self::$submitDocumentsOverride)($documents);
        }

        $doc = $documents[0] ?? [];
        $codeNumber = $doc['codeNumber'] ?? 'MOCK-INV-001';
        $mockUuid = 'MOCK-'.strtoupper(Str::random(16));
        $mockSubUid = 'MOCKSUB-'.strtoupper(Str::random(12));

        return [
            'submissionUid' => $mockSubUid,
            'acceptedDocuments' => [
                [
                    'uuid' => $mockUuid,
                    'invoiceCodeNumber' => $codeNumber,
                ],
            ],
            'rejectedDocuments' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubmission(string $submissionUid): array
    {
        return [
            'submissionUid' => $submissionUid,
            'status' => 'Valid',
            'documentCount' => 1,
            'dateTimeReceived' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentDetails(string $uuid): array
    {
        if (self::$documentDetailsOverride !== null) {
            return (self::$documentDetailsOverride)($uuid);
        }

        $mockLongId = 'MOCKLONG'.strtoupper(Str::random(24));
        $now = CarbonImmutable::now('UTC')->toIso8601String();

        return [
            'uuid' => $uuid,
            'submissionUid' => 'MOCKSUB-DETAIL',
            'longId' => $mockLongId,
            'status' => 'Valid',
            'dateTimeIssued' => $now,
            'dateTimeReceived' => $now,
            'dateTimeValidated' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelDocument(string $uuid, string $reason): array
    {
        return [
            'uuid' => $uuid,
            'status' => 'Cancelled',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateTin(string $tin, string $idType, string $idValue): array
    {
        return [];
    }
}
