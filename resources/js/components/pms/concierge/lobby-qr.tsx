import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

/** The lobby QR code with its link underneath. `className` sets the size. */
export function LobbyQr({
    url,
    qr,
    className,
}: {
    url: string;
    qr: string;
    className: string;
}) {
    return (
        <>
            <img
                src={qr}
                alt={`QR code for ${url}`}
                width={320}
                height={320}
                className={cn('rounded-lg bg-white p-2', className)}
            />
            <p className="text-center text-sm break-all text-muted-foreground">
                {url}
            </p>
        </>
    );
}

export function CopyLinkButton({
    url,
    className,
}: {
    url: string;
    className?: string;
}) {
    const [copied, copy] = useClipboard();
    const done = copied === url;
    const Icon = done ? Check : Copy;

    return (
        <Button
            type="button"
            variant="ghost"
            className={cn('min-h-11', className)}
            onClick={() => copy(url)}
        >
            <Icon data-icon="inline-start" />
            {done ? 'Copied' : 'Copy link'}
        </Button>
    );
}

/** The sheet that comes out of the printer. Hidden on screen, shown only when printing. */
export function LobbyPrintCard({ url, qr }: { url: string; qr: string }) {
    return (
        <Card className="hidden print:flex">
            <CardHeader>
                <CardTitle>Lobby check-in</CardTitle>
                <CardDescription>
                    Scan this QR code to open the lobby page on your phone.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col items-center gap-4">
                <LobbyQr url={url} qr={qr} className="size-64" />
            </CardContent>
        </Card>
    );
}
