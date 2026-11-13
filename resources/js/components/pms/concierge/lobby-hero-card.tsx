import { router } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { destroy, store } from '@/routes/settings/concierge/hero';

/** The photo beside the property name on the lobby page. It saves as soon as a file is picked. */
export function LobbyHeroCard({ heroUrl }: { heroUrl: string | null }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            store.url(),
            { hero: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onError: (errors) => setError(errors.hero),
                onFinish: () => {
                    setBusy(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Lobby photo</CardTitle>
                <CardDescription>
                    The large photo guests see on the lobby page. Without one, a
                    stock coast photo is shown.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="aspect-video overflow-hidden rounded-md border bg-muted">
                    <img
                        src={heroUrl ?? '/images/concierge-editorial-coast.jpg'}
                        alt="Lobby photo preview"
                        className="size-full object-cover"
                    />
                </div>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        focusableWhenDisabled
                        onClick={() => fileInput.current?.click()}
                    >
                        {busy ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Upload data-icon="inline-start" />
                        )}
                        {heroUrl ? 'Replace photo' : 'Upload photo'}
                    </Button>
                    {heroUrl && (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            focusableWhenDisabled
                            onClick={() =>
                                router.delete(destroy.url(), {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                })
                            }
                        >
                            <Trash2 data-icon="inline-start" />
                            Use stock photo
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    JPEG, PNG or WebP, up to 5 MB. Landscape works best.
                </p>
                <FieldError>{error}</FieldError>
            </CardContent>
        </Card>
    );
}
