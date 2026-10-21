<?php

namespace App\Services\Channels\Channex;

/**
 * Builds the Channel API payloads from an adapter descriptor (`GET /channels/adapter`). It holds no state.
 */
class ChannexChannelMapper
{
    /**
     * The connection settings Channex takes: one value per visible adapter param. Hidden params are Channex's own, a
     * param a rule hides is dropped, a value left empty falls back to the adapter default, and a select keeps only
     * one of its options.
     *
     * @param  array<string, mixed>  $adapter
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function settings(array $adapter, array $input): array
    {
        $params = $adapter['params'] ?? [];
        $values = [];

        foreach ($params as $name => $param) {
            if (($param['type'] ?? null) === 'hidden') {
                continue;
            }

            $value = $this->value($param, $input[$name] ?? null);

            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return array_filter($values, fn (mixed $value, string $name): bool => ! $this->isHidden($params[$name], $values), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The body of `POST /channels`. The channel starts disabled with no rate mapping.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function createPayload(string $adapterCode, string $title, string $groupId, string $externalPropertyId, array $settings): array
    {
        return [
            'channel' => $adapterCode,
            'group_id' => $groupId,
            'title' => $title,
            'properties' => [$externalPropertyId],
            'settings' => $settings,
        ];
    }

    /**
     * @param  array<string, mixed>  $param
     */
    private function value(array $param, mixed $input): string|bool|int|null
    {
        $value = $input === null || $input === '' ? ($param['default'] ?? null) : $input;

        if ($value === null) {
            return null;
        }

        return match ($param['type'] ?? 'string') {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => is_numeric($value) ? (int) $value : null,
            'select' => in_array($value, $param['options'] ?? [], true) ? $value : ($param['default'] ?? null),
            default => trim((string) $value) === '' ? null : trim((string) $value),
        };
    }

    /**
     * A rule `{apply: hidden, when: false, influence_field: x}` hides the param while field x equals `when`.
     *
     * @param  array<string, mixed>  $param
     * @param  array<string, mixed>  $values
     */
    private function isHidden(array $param, array $values): bool
    {
        foreach ($param['rules'] ?? [] as $rule) {
            if (($rule['apply'] ?? null) === 'hidden' && ($values[$rule['influence_field'] ?? ''] ?? null) === ($rule['when'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
