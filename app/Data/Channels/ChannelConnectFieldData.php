<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One input on the Connect form, drawn from an adapter param. `hiddenWhenField` and `hiddenWhenValue` hide the input
 * while another field has that value.
 */
class ChannelConnectFieldData extends Data
{
    /**
     * @param  'string'|'boolean'|'select'|'integer'  $type
     * @param  list<string>  $options
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public ?string $hint,
        public ?string $placeholder,
        public array $options,
        public string|bool|int|null $default,
        public bool $required,
        public ?string $hiddenWhenField,
        public bool|string|null $hiddenWhenValue,
    ) {}
}
