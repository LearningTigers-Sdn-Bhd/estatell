import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

export type QrVariant = {
    id: number;
    name: string;
    price: string;
    soldOut: boolean;
};

export type QrItem = { id: number; name: string; variants: QrVariant[] };

export type QrCategory = { id: number; name: string; items: QrItem[] };

export type QrLine = {
    variant_id: number;
    quantity: number;
    note: string | null;
};

type CartRow = {
    variant: QrVariant;
    item: string;
    quantity: number;
    note: string;
};

/**
 * A guest's menu and cart. Prices and sold-out flags come from the server; the cart only adds up what the guest picked, and
 * the server prices the order again when it is sent.
 */
export function QrMenu({
    categories,
    allowRemark,
    sending,
    onSend,
}: {
    categories: QrCategory[];
    allowRemark: boolean;
    sending: boolean;
    onSend: (lines: QrLine[]) => void;
}) {
    const [categoryId, setCategoryId] = useState<number | null>(
        categories[0]?.id ?? null,
    );
    const [cart, setCart] = useState<Record<number, CartRow>>({});
    const [remark, setRemark] = useState('');

    const category =
        categories.find((row) => row.id === categoryId) ?? categories[0];
    const rows = Object.values(cart);
    const total = rows.reduce(
        (sum, row) => sum + Number(row.variant.price) * row.quantity,
        0,
    );

    const add = (item: QrItem, variant: QrVariant) =>
        setCart((current) => ({
            ...current,
            [variant.id]: {
                variant,
                item: item.name,
                quantity: (current[variant.id]?.quantity ?? 0) + 1,
                note: current[variant.id]?.note ?? '',
            },
        }));

    const change = (variantId: number, quantity: number) =>
        setCart((current) => {
            const next = { ...current };

            if (quantity <= 0) {
                delete next[variantId];
            } else if (next[variantId]) {
                next[variantId] = { ...next[variantId], quantity };
            }

            return next;
        });

    const send = () =>
        onSend(
            rows.map((row) => ({
                variant_id: row.variant.id,
                quantity: row.quantity,
                note: allowRemark
                    ? [row.note.trim(), remark.trim()]
                          .filter((text) => text !== '')
                          .join(' · ') || null
                    : null,
            })),
        );

    return (
        <div className="flex flex-col gap-4 p-4 pb-40">
            <nav className="flex gap-2 overflow-x-auto">
                {categories.map((row) => (
                    <Button
                        key={row.id}
                        size="lg"
                        variant={
                            row.id === category?.id ? 'default' : 'outline'
                        }
                        onClick={() => setCategoryId(row.id)}
                    >
                        {row.name}
                    </Button>
                ))}
            </nav>

            {category?.items.map((item) => (
                <Card key={item.id}>
                    <CardHeader>
                        <CardTitle className="text-lg">{item.name}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {item.variants.map((variant) => (
                            <Button
                                key={variant.id}
                                size="lg"
                                variant="outline"
                                disabled={variant.soldOut}
                                onClick={() => add(item, variant)}
                            >
                                {variant.name === 'Regular'
                                    ? item.name
                                    : variant.name}{' '}
                                <span className="tabular-nums">
                                    RM {Number(variant.price).toFixed(2)}
                                </span>
                                {variant.soldOut && (
                                    <Badge variant="secondary">Sold out</Badge>
                                )}
                            </Button>
                        ))}
                    </CardContent>
                </Card>
            ))}

            <div className="fixed inset-x-0 bottom-0 flex flex-col gap-3 border-t bg-background p-4">
                {rows.map((row) => (
                    <div
                        key={row.variant.id}
                        className="flex items-center justify-between gap-2"
                    >
                        <span className="min-w-0 truncate">{row.item}</span>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="Less"
                                onClick={() =>
                                    change(row.variant.id, row.quantity - 1)
                                }
                            >
                                −
                            </Button>
                            <span className="w-6 text-center tabular-nums">
                                {row.quantity}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="More"
                                onClick={() =>
                                    change(row.variant.id, row.quantity + 1)
                                }
                            >
                                +
                            </Button>
                        </div>
                    </div>
                ))}
                {allowRemark && rows.length > 0 && (
                    <Input
                        placeholder="Note for the kitchen (optional)"
                        maxLength={200}
                        value={remark}
                        onChange={(event) => setRemark(event.target.value)}
                    />
                )}
                <Button
                    size="lg"
                    disabled={rows.length === 0 || sending}
                    onClick={send}
                >
                    Send order · RM {total.toFixed(2)}
                </Button>
            </div>
        </div>
    );
}
