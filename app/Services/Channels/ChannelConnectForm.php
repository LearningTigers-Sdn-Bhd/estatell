<?php

namespace App\Services\Channels;

use App\Data\Channels\ChannelConnectFieldData;

/**
 * Turns an adapter descriptor into the inputs of the Connect form, in the adapter's order. Hidden params are Channex's
 * own and never shown. It writes nothing.
 */
class ChannelConnectForm
{
    public function __construct(private ChannelFieldCopy $copy) {}

    /**
     * @param  array<string, mixed>  $adapter
     * @return list<ChannelConnectFieldData>
     */
    public function fields(string $adapterCode, array $adapter): array
    {
        $params = array_filter($adapter['params'] ?? [], fn (array $param): bool => ($param['type'] ?? null) !== 'hidden');
        uasort($params, fn (array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $fields = [];

        foreach ($params as $name => $param) {
            $copy = $this->copy->for($adapterCode, $name);
            $rule = array_find($param['rules'] ?? [], fn (array $rule): bool => ($rule['apply'] ?? null) === 'hidden');
            $default = $param['default'] ?? null;

            $fields[] = new ChannelConnectFieldData(
                name: $name,
                type: in_array($param['type'] ?? 'string', ['boolean', 'select', 'integer'], true) ? $param['type'] : 'string',
                label: $copy['label'] ?? $param['title'] ?? $name,
                hint: $copy['hint'],
                placeholder: $copy['placeholder'],
                options: array_values($param['options'] ?? []),
                default: is_bool($default) || is_int($default) || is_string($default) ? $default : null,
                required: ChannelHotelId::isKey($name),
                hiddenWhenField: $rule['influence_field'] ?? null,
                hiddenWhenValue: $rule === null ? null : ($rule['when'] ?? null),
            );
        }

        return $fields;
    }
}
