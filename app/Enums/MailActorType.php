<?php

namespace App\Enums;

/**
 * Who caused an email to be sent. See docs/04-notifications/03-mail-log.md.
 */
enum MailActorType: string
{
    case System = 'system';
    case Property = 'property';
    case Partner = 'partner';
    case Admin = 'admin';
    case Guest = 'guest';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::System => __('System'),
            self::Property => __('Property'),
            self::Partner => __('Partner'),
            self::Admin => __('Admin'),
            self::Guest => __('Guest'),
            self::Company => __('Company'),
        };
    }
}
