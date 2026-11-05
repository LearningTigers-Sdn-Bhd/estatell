<?php

namespace App\Actions\EInvoice;

use App\Enums\EInvoiceEnvironment;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaveEInvoiceSettings
{
    /**
     * @param  array{
     *     enabled?: bool,
     *     api_environment?: string|EInvoiceEnvironment,
     *     client_id?: ?string,
     *     client_secret?: ?string,
     *     signature_enabled?: bool,
     *     signing_certificate?: ?string,
     *     signing_private_key?: ?string,
     *     signing_passphrase?: ?string,
     *     supplier_name?: ?string,
     *     supplier_tin?: ?string,
     *     supplier_sst_registration?: ?string,
     *     supplier_ttx_registration?: ?string,
     *     supplier_msic_code?: ?string,
     *     supplier_business_description?: ?string,
     *     supplier_email?: ?string,
     *     supplier_phone?: ?string,
     *     supplier_address_line1?: ?string,
     *     supplier_address_line2?: ?string,
     *     supplier_city?: ?string,
     *     supplier_postal_code?: ?string,
     *     supplier_state_code?: ?string,
     *     auto_submit_on_checkout?: bool,
     *     consolidation_enabled?: bool
     * }  $attributes
     */
    public function __invoke(Property $property, array $attributes, User $actor): PropertyEInvoiceSetting
    {
        return DB::transaction(function () use ($property, $attributes, $actor): PropertyEInvoiceSetting {
            $setting = $property->eInvoiceSetting ?? new PropertyEInvoiceSetting(['property_id' => $property->id]);

            $enabled = (bool) ($attributes['enabled'] ?? $setting->enabled);
            $signatureEnabled = (bool) ($attributes['signature_enabled'] ?? $setting->signature_enabled);

            $cert = $attributes['signing_certificate'] ?? $setting->signing_certificate;
            $privateKey = $attributes['signing_private_key'] ?? $setting->signing_private_key;

            if ($signatureEnabled && (empty($cert) || empty($privateKey))) {
                throw new InvalidArgumentException(__('A digital signing certificate and private key are required before document signing can be enabled.'));
            }

            if ($enabled && $setting->effective_from === null) {
                $setting->effective_from = CarbonImmutable::now();
            }

            $env = $attributes['api_environment'] ?? $setting->api_environment ?? EInvoiceEnvironment::Sandbox;
            if (is_string($env)) {
                $env = EInvoiceEnvironment::from($env);
            }

            $setting->enabled = $enabled;
            $setting->api_environment = $env;

            if (array_key_exists('client_id', $attributes) && ! empty($attributes['client_id'])) {
                $setting->client_id = $attributes['client_id'];
            }
            if (array_key_exists('client_secret', $attributes) && ! empty($attributes['client_secret'])) {
                $setting->client_secret = $attributes['client_secret'];
            }

            $setting->signature_enabled = $signatureEnabled;
            if (array_key_exists('signing_certificate', $attributes)) {
                $setting->signing_certificate = $attributes['signing_certificate'];
            }
            if (array_key_exists('signing_private_key', $attributes) && ! empty($attributes['signing_private_key'])) {
                $setting->signing_private_key = $attributes['signing_private_key'];
            }
            if (array_key_exists('signing_passphrase', $attributes)) {
                $setting->signing_passphrase = $attributes['signing_passphrase'];
            }

            foreach ([
                'supplier_name', 'supplier_tin', 'supplier_sst_registration', 'supplier_ttx_registration',
                'supplier_msic_code', 'supplier_business_description', 'supplier_email', 'supplier_phone',
                'supplier_address_line1', 'supplier_address_line2', 'supplier_city', 'supplier_postal_code',
                'supplier_state_code',
            ] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $setting->{$field} = $attributes[$field];
                }
            }

            if (array_key_exists('auto_submit_on_checkout', $attributes)) {
                $setting->auto_submit_on_checkout = (bool) $attributes['auto_submit_on_checkout'];
            }
            if (array_key_exists('consolidation_enabled', $attributes)) {
                $setting->consolidation_enabled = (bool) $attributes['consolidation_enabled'];
            }

            $setting->save();

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('einvoice_settings.updated')
                ->withProperties([
                    'property_id' => $property->id,
                    'enabled' => $setting->enabled,
                    'environment' => $setting->api_environment->value,
                    'signature_enabled' => $setting->signature_enabled,
                ])
                ->log('Updated e-invoice settings');

            return $setting;
        });
    }
}
