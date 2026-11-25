<?php

namespace App\Services\Pos;

/**
 * The part of a register menu a guest can order alone from a QR: no combo, and no item whose modifier group must be chosen.
 * Reads and shapes only. Prices and sold-out flags are the snapshot's.
 */
class QrMenu
{
    /**
     * @param  list<array<string, mixed>>  $categories  The `categories` of an OutletMenuSnapshot.
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $categories): array
    {
        $orderable = [];

        foreach ($categories as $category) {
            $items = [];

            foreach ($category['items'] as $item) {
                if ($item['isCombo'] || $this->needsChoice($item['modifierGroups'])) {
                    continue;
                }

                $items[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'variants' => array_map(fn (array $variant): array => [
                        'id' => $variant['id'],
                        'name' => $variant['name'],
                        'price' => $variant['price'],
                        'soldOut' => $variant['soldOut'],
                    ], $item['variants']),
                ];
            }

            if ($items !== []) {
                $orderable[] = ['id' => $category['id'], 'name' => $category['name'], 'items' => $items];
            }
        }

        return $orderable;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function needsChoice(array $groups): bool
    {
        foreach ($groups as $group) {
            if ((int) $group['minSelect'] > 0) {
                return true;
            }
        }

        return false;
    }
}
