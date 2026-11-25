import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
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
    return (
        <>
            <Head title={`QR · ${table}`} />
            <div className="mx-auto flex w-full max-w-lg flex-col gap-4 p-6">
                <Link
                    href={posIndex.url()}
                    className={buttonVariants({ variant: 'ghost' })}
                >
                    <ArrowLeft data-icon="inline-start" />
                    Back to the register
                </Link>
                <Card>
                    <CardHeader>
                        <CardTitle>Table {table} ordering QR</CardTitle>
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
                        <div className="pos-print-area mx-auto flex w-full max-w-[80mm] flex-col items-center gap-2 text-center">
                            <p className="text-base font-semibold">
                                Table {table}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Scan to view the menu and order
                            </p>
                            <div
                                className="size-44 shrink-0 [&>svg]:size-full"
                                role="img"
                                aria-label={`QR code for table ${table}`}
                                dangerouslySetInnerHTML={{ __html: qrSvg }}
                            />
                            <p className="font-mono text-xs break-all">{url}</p>
                        </div>
                        <Input
                            readOnly
                            aria-label="Table ordering link"
                            value={url}
                            onFocus={(event) => event.currentTarget.select()}
                        />
                        <Button
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            <Printer data-icon="inline-start" />
                            Print QR slip
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
