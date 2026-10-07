import { Form, router } from '@inertiajs/react';
import { ImageOff, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { destroy, store } from '@/routes/settings/logo';

/**
 * The property logo. It uploads as soon as a file is picked and is not part of the profile Save.
 */
export function PropertyLogoField({ logoUrl }: { logoUrl: string | null }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string>();
    const [removeOpen, setRemoveOpen] = useState(false);

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            store.url(),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onError: (errors) => setError(errors.logo),
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <Field className="md:col-span-2">
            <FieldLabel htmlFor="logo">Logo</FieldLabel>
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex h-16 w-28 items-center justify-center rounded-md border bg-muted/40 p-2">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt="Property logo"
                            className="max-h-full max-w-full object-contain"
                        />
                    ) : (
                        <ImageOff className="size-5 text-muted-foreground" />
                    )}
                </div>
                <input
                    ref={fileInput}
                    id="logo"
                    type="file"
                    accept="image/png,image/jpeg"
                    className="hidden"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={uploading}
                    focusableWhenDisabled
                    onClick={() => fileInput.current?.click()}
                >
                    {uploading ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Upload data-icon="inline-start" />
                    )}
                    {logoUrl ? 'Replace' : 'Upload logo'}
                </Button>
                {logoUrl && (
                    <AlertDialog open={removeOpen} onOpenChange={setRemoveOpen}>
                        <AlertDialogTrigger
                            render={<Button type="button" variant="ghost" />}
                        >
                            Remove
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Remove the logo?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    PDFs will start the header with the property
                                    name. You can upload a logo again later.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <Form
                                {...destroy.form()}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRemoveOpen(false)}
                            >
                                {({ processing }) => (
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Remove logo
                                        </Button>
                                    </AlertDialogFooter>
                                )}
                            </Form>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </div>
            <FieldDescription>
                PNG or JPEG, up to 1 MB. Printed at the top of PDFs.
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
