<?php

namespace App\Services\EInvoice;

/**
 * Turns a built LHDN document into what the Submit Documents API takes: the JSON, base64 encoded, with its SHA-256
 * hash and the hotel's own number for it (`codeNumber`). It reads only.
 */
class DocumentEncoder
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{format: string, document: string, documentHash: string, codeNumber: string, payload: array<string, mixed>}
     */
    public function encode(array $payload, string $codeNumber): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'format' => 'JSON',
            'document' => base64_encode($json),
            'documentHash' => hash('sha256', $json),
            'codeNumber' => $codeNumber,
            'payload' => $payload,
        ];
    }
}
