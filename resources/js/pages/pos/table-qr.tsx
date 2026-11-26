import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Copy, Printer } from 'lucide-react';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { index as posIndex } from '@/routes/pos';

type Props = {
    table: string;
    url: string;
    qrSvg: string;
    expiresAt: string | null;
};

/**
 * The dynamic QR slip to print for one table. It replaces any earlier link of the table and prints on 80 mm through
 * `.pos-print-area`. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
export default function PosTableQr({ table, url, qrSvg, expiresAt }: Props) {
    const [copied, setCopied] = useState(false);

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard permission may be unavailable on non-HTTPS local hosts.
        }
    };

    return (
        <>
            <Head title={`QR · ${table}`} />
            <main className="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center gap-4 p-6">
                <Link
                    href={posIndex.url()}
                    className={buttonVariants({
                        variant: 'ghost',
                        className: 'min-h-11 self-start',
                    })}
                >
                    <ArrowLeft data-icon="inline-start" aria-hidden="true" />
                    Back to the register
                </Link>
                <Card>
                    <CardHeader>
                        <CardTitle className="font-heading text-xl">
                            Table {table} ordering QR
                        </CardTitle>
                        <CardDescription>
                            Guests scan this code on their phone to order. It
                            stops working when the table is settled
                            {expiresAt !== null
                                ? ` or at ${new Date(expiresAt).toLocaleTimeString()}`
                                : ''}
                            .
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="pos-print-area mx-auto flex w-full max-w-xs flex-col items-center gap-2 rounded-xl border bg-background p-4 text-center">
                            <p className="font-heading text-base font-semibold">
                                Table {table}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Scan to view the menu and order
                            </p>
                            <div
                                className="size-48 shrink-0 rounded-lg border bg-primary-foreground p-2 [&>svg]:size-full"
                                role="img"
                                aria-label={`QR code for table ${table}`}
                                dangerouslySetInnerHTML={{ __html: qrSvg }}
                            />
                            <p className="font-mono text-xs break-all text-muted-foreground">
                                {url}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                aria-label="Table ordering link"
                                className="h-11"
                                value={url}
                                onFocus={(event) =>
                                    event.currentTarget.select()
                                }
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 shrink-0"
                                onClick={() => void copyLink()}
                            >
                                {copied ? (
                                    <Check
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Copy
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                )}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <Button
                            type="button"
                            className="min-h-11 font-semibold"
                            onClick={() => window.print()}
                        >
                            <Printer
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            Print QR slip
                        </Button>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
