<?php

namespace App\Enums;

use App\Models\AccountingConnection;
use App\Services\Accounting\AccountingGateway;
use App\Services\Accounting\BukkuGateway;
use App\Services\Accounting\QneGateway;

enum AccountingProvider: string
{
    case Qne = 'qne';
    case Bukku = 'bukku';

    public function label(): string
    {
        return match ($this) {
            self::Qne => __('QNE Cloud Accounting'),
            self::Bukku => __('Bukku'),
        };
    }

    /**
     * One line that helps a property tell the providers apart.
     */
    public function description(): string
    {
        return match ($this) {
            self::Qne => __('Cloud accounting with payroll, stock and e-invoice. No test server.'),
            self::Bukku => __('Simple cloud accounting for small businesses. Has a test server.'),
        };
    }

    /**
     * What the provider calls the secret the property pastes in.
     */
    public function keyLabel(): string
    {
        return match ($this) {
            self::Qne => __('API key'),
            self::Bukku => __('Access token'),
        };
    }

    /**
     * Where the property finds that secret.
     */
    public function keyHelp(): string
    {
        return match ($this) {
            self::Qne => __('Create an API key in QNE under My Apps, then paste it here.'),
            self::Bukku => __('In Bukku open Control Panel, then Integrations, turn on API Access and save to get the access token.'),
        };
    }

    /**
     * Bukku finds the company by the subdomain of its Bukku address. QNE's key already belongs to one company.
     */
    public function needsCompanySubdomain(): bool
    {
        return match ($this) {
            self::Qne => false,
            self::Bukku => true,
        };
    }

    /**
     * Whether the provider has a separate test server. QNE has none.
     */
    public function supportsSandbox(): bool
    {
        return match ($this) {
            self::Qne => false,
            self::Bukku => true,
        };
    }

    /**
     * The strategy that talks to this provider for one connection.
     */
    public function gateway(AccountingConnection $connection): AccountingGateway
    {
        return match ($this) {
            self::Qne => new QneGateway((string) $connection->api_key, (string) config('services.qne.base_url')),
            self::Bukku => new BukkuGateway(
                (string) $connection->api_key,
                (string) $connection->company_subdomain,
                (string) config($connection->sandbox ? 'services.bukku.staging_url' : 'services.bukku.base_url'),
            ),
        };
    }
}
