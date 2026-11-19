import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
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
            className={className}
            onClick={() => copy(url)}
        >
            <Icon data-icon="inline-start" />
            {done ? 'Copied' : 'Copy link'}
        </Button>
    );
}

const escapeHtml = (value: string) =>
    value.replace(
        /[&<>"']/g,
        (char) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[char] as string,
    );

/** The A4 poster a property puts up in its lobby: its name, a large QR code and one line of what the code opens. */
function lobbyPosterHtml({
    name,
    logoUrl,
    url,
    qr,
}: {
    name: string;
    logoUrl: string | null;
    url: string;
    qr: string;
}) {
    const logo = logoUrl
        ? `<img class="logo" src="${escapeHtml(new URL(logoUrl, window.location.origin).href)}" alt="">`
        : '';

    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>${escapeHtml(name)} lobby QR</title>
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; margin: 0; }
html, body { width: 210mm; height: 297mm; }
body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #fff; color: #26260f; font-family: Georgia, 'Times New Roman', serif; }
.sheet { position: relative; display: flex; width: 210mm; height: 297mm; flex-direction: column; align-items: center; justify-content: center; gap: 9mm; padding: 24mm 22mm; text-align: center; }
.sheet::before { content: ''; position: absolute; inset: 9mm; border: 0.6mm solid #3b3a17; }
.sheet::after { content: ''; position: absolute; inset: 11.5mm; border: 0.2mm solid #3b3a17; }
.logo { max-width: 60mm; max-height: 26mm; object-fit: contain; }
.eyebrow { font: 600 9pt/1 Helvetica, Arial, sans-serif; letter-spacing: 0.32em; text-transform: uppercase; }
h1 { font-size: 40pt; font-weight: 400; line-height: 1; letter-spacing: -0.03em; overflow-wrap: anywhere; }
.rule { width: 30mm; height: 0.5mm; background: #3b3a17; }
.cta { font-size: 24pt; font-style: italic; }
.frame { position: relative; padding: 9mm; background: #fff; border: 0.3mm solid #3b3a17; }
.frame::before, .frame::after, .frame i { content: ''; position: absolute; width: 14mm; height: 14mm; border: 1.6mm solid #3b3a17; }
.frame::before { top: -3mm; left: -3mm; border-right: 0; border-bottom: 0; }
.frame::after { right: -3mm; bottom: -3mm; border-left: 0; border-top: 0; }
.frame .tr { top: -3mm; right: -3mm; border-left: 0; border-bottom: 0; }
.frame .bl { bottom: -3mm; left: -3mm; border-right: 0; border-top: 0; }
.frame img { display: block; width: 88mm; height: 88mm; }
.hint { max-width: 120mm; font-size: 14pt; line-height: 1.45; }
</style></head><body>
<div class="sheet">
${logo}
<p class="eyebrow">Welcome to</p>
<h1>${escapeHtml(name)}</h1>
<div class="rule"></div>
<p class="cta">Scan this QR code</p>
<div class="frame"><i class="tr"></i><i class="bl"></i><img src="${qr}" alt="QR code for ${escapeHtml(url)}"></div>
<p class="hint">Point your phone camera at the code. Check in, read the property guide, find places nearby or contact us.</p>
</div></body></html>`;
}

/**
 * Prints the lobby poster on its own, apart from the staff screen. The poster is built in a hidden frame so the
 * printed page holds only the poster.
 */
export function printLobbyPoster(poster: {
    name: string;
    logoUrl: string | null;
    url: string;
    qr: string;
}) {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText =
        'position:fixed;width:0;height:0;border:0;visibility:hidden';
    frame.srcdoc = lobbyPosterHtml(poster);
    frame.onload = async () => {
        const win = frame.contentWindow;

        if (!win) {
            return;
        }

        // Wait for the logo and the QR code, or the sheet prints without them.
        await Promise.all(
            Array.from(win.document.images).map((image) =>
                image.decode().catch(() => undefined),
            ),
        );
        win.addEventListener('afterprint', () => frame.remove());
        win.focus();
        win.print();
    };
    document.body.appendChild(frame);
}
