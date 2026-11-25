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
    expiresAt: string | null;
};

/**
 * The link to print for one table. It replaces any earlier link of the table. Printing uses the browser; a QR picture is not
 * drawn yet, so the link is printed as text. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
export default function PosTableQr({ table, url, expiresAt }: Props) {
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
                        <CardTitle>Table {table} ordering link</CardTitle>
                        <CardDescription>
                            Guests open this link on their phone to order. It
                            stops working when the table is settled
                            {expiresAt !== null
                                ? ` or at ${new Date(expiresAt).toLocaleTimeString()}`
                                : ''}
                            .
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <Input
                            readOnly
                            value={url}
                            onFocus={(event) => event.currentTarget.select()}
                        />
                        <Button
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            <Printer data-icon="inline-start" />
                            Print
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
