<?php

namespace App\Services\EInvoice;

use App\Models\PropertyEInvoiceSetting;
use RuntimeException;

/**
 * Known not LHDN-compliant: do not enable signature_enabled for a real property.
 *
 * The v1.1 signature LHDN expects (https://sdk.myinvois.hasil.gov.my/signature-creation-json/)
 * needs a SignedProperties block with a certificate digest and signing time, a document
 * digest over the minified document without UBLExtensions and Signature, and an RSA
 * signature over that digest. This class signs a digest of a digest and has no
 * SignedProperties, so LHDN rejects it. WAStays hit the same failure live (DS300).
 *
 * The settings form no longer offers signing and the controller does not accept it,
 * so every document goes out as unsigned v1.0. Fix and verify this class against
 * LHDN preprod before restoring the signing fields.
 */
class DocumentSigner
{
    public const string VERSION_UNSIGNED = '1.0';

    public const string VERSION_SIGNED = '1.1';

    public const string SIGNATURE_ID = 'urn:oasis:names:specification:ubl:signature:Invoice';

    public const string SIGNATURE_METHOD = 'urn:oasis:names:specification:ubl:dsig:enveloped:xades';

    public function __construct(private ?PropertyEInvoiceSetting $setting) {}

    public function isEnabled(): bool
    {
        return $this->setting?->signature_enabled === true;
    }

    public function documentVersion(): string
    {
        return $this->isEnabled() ? self::VERSION_SIGNED : self::VERSION_UNSIGNED;
    }

    /**
     * Applies cryptographic signing block to payload if signature is enabled.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        if (! $this->isEnabled()) {
            return $payload;
        }

        if (empty($this->setting->signing_certificate) || empty($this->setting->signing_private_key)) {
            throw new RuntimeException('E-invoice digital signing is enabled, but signing certificate or private key is missing.');
        }

        $invoice = &$payload['Invoice'][0];
        $documentDigest = base64_encode(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR), true));

        // Format certificate string by stripping headers and whitespace
        $certBody = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $this->setting->signing_certificate);

        // Sign the digest with private key
        $privateKey = openssl_pkey_get_private($this->setting->signing_private_key, $this->setting->signing_passphrase ?? '');
        if ($privateKey === false) {
            throw new RuntimeException('Failed to load private key for e-invoice document signing: '.openssl_error_string());
        }

        $signatureBinary = '';
        $success = openssl_sign($documentDigest, $signatureBinary, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $success) {
            throw new RuntimeException('Failed to generate RSA-SHA256 signature for e-invoice: '.openssl_error_string());
        }

        $signatureValue = base64_encode($signatureBinary);

        $invoice['UBLExtensions'] = [
            [
                'UBLExtension' => [
                    [
                        'ExtensionURI' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']],
                        'ExtensionContent' => [
                            [
                                'UBLDocumentSignatures' => [
                                    [
                                        'SignatureInformation' => [
                                            [
                                                'ID' => [['_' => 'urn:oasis:names:specification:ubl:signature:Enveloped']],
                                                'ReferencedSignatureID' => [['_' => self::SIGNATURE_ID]],
                                                'Signature' => [
                                                    [
                                                        'SignedInfo' => [
                                                            [
                                                                'SignatureMethod' => [['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']],
                                                                'Reference' => [
                                                                    [
                                                                        'ID' => [['_' => 'document']],
                                                                        'DigestMethod' => [['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']],
                                                                        'DigestValue' => [['_' => $documentDigest]],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                        'SignatureValue' => [['_' => $signatureValue]],
                                                        'KeyInfo' => [
                                                            [
                                                                'X509Data' => [
                                                                    [
                                                                        'X509Certificate' => [['_' => $certBody]],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $invoice['Signature'] = [
            [
                'ID' => [['_' => self::SIGNATURE_ID]],
                'SignatureMethod' => [['_' => self::SIGNATURE_METHOD]],
            ],
        ];

        return $payload;
    }
}
