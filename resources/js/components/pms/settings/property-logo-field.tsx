import { Form } from '@inertiajs/react';
import { Eye, ImageIcon, Pencil, Trash2, Upload } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PanelHeading } from '@/components/pms/panel-heading';
import { LogoCropDialog } from '@/components/pms/settings/logo-crop-dialog';
import type { LogoSource } from '@/components/pms/settings/logo-crop-dialog';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldDescription, FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { destroy } from '@/routes/settings/logo';

/** The largest file the browser accepts before cropping. Only the small cropped file is uploaded. */
const MAX_PICK_BYTES = 10 * 1024 * 1024;

const ACCEPTED_TYPES = ['image/png', 'image/jpeg'] as const;

function isAcceptedType(type: string): type is LogoSource['type'] {
    return (ACCEPTED_TYPES as readonly string[]).includes(type);
}

/**
 * The property logo in a rounded tile. A picked image opens the crop dialog, which uploads a square file. The logo
 * is not part of the profile Save.
 */
export function PropertyLogoField({ logoUrl }: { logoUrl: string | null }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [source, setSource] = useState<LogoSource | null>(null);
    const [cropOpen, setCropOpen] = useState(false);
    const [viewOpen, setViewOpen] = useState(false);
    const [removeOpen, setRemoveOpen] = useState(false);
    const [loadingCurrent, setLoadingCurrent] = useState(false);
    const [error, setError] = useState<string>();

    useEffect(() => {
        return () => {
            if (source) {
                URL.revokeObjectURL(source.url);
            }
        };
    }, [source]);

    const openCrop = (blob: Blob) => {
        if (!isAcceptedType(blob.type)) {
            setError('The logo must be a PNG or JPEG image.');

            return;
        }

        if (blob.size > MAX_PICK_BYTES) {
            setError('Choose an image up to 10 MB.');

            return;
        }

        setError(undefined);
        setSource({ url: URL.createObjectURL(blob), type: blob.type });
        setCropOpen(true);
    };

    const pickFile = () => fileInput.current?.click();

    const editCurrent = async () => {
        if (!logoUrl) {
            return;
        }

        setLoadingCurrent(true);

        try {
            const response = await fetch(logoUrl);

            if (!response.ok) {
                throw new Error();
            }

            openCrop(await response.blob());
        } catch {
            setError('The current logo could not be loaded.');
        } finally {
            setLoadingCurrent(false);
        }
    };

    return (
        <section className="flex flex-col gap-4">
            <PanelHeading
                icon={ImageIcon}
                title="Logo"
                description="Shown in the app and printed at the top of PDFs. It is saved as soon as you crop it."
            />
            <Field>
                <input
                    ref={fileInput}
                    type="file"
                    accept={ACCEPTED_TYPES.join(',')}
                    className="hidden"
                    aria-label="Logo file"
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = '';

                        if (file) {
                            openCrop(file);
                        }
                    }}
                />
                <div>
                    {logoUrl ? (
                        <div className="group relative flex size-28 items-center justify-center overflow-hidden rounded-2xl border bg-muted">
                            <img
                                src={logoUrl}
                                alt="Property logo"
                                className="size-full object-contain"
                            />
                            <div className="absolute inset-0 flex items-center justify-center gap-1 bg-foreground/60 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 pointer-coarse:opacity-100">
                                <TileAction
                                    icon={Eye}
                                    label="View logo"
                                    onClick={() => setViewOpen(true)}
                                />
                                <TileAction
                                    icon={Pencil}
                                    label="Edit logo"
                                    loading={loadingCurrent}
                                    onClick={editCurrent}
                                />
                                <TileAction
                                    icon={Trash2}
                                    label="Delete logo"
                                    onClick={() => setRemoveOpen(true)}
                                />
                            </div>
                        </div>
                    ) : (
                        <button
                            type="button"
                            className="flex size-28 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed bg-muted text-muted-foreground transition-colors outline-none hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50"
                            onClick={pickFile}
                        >
                            <Upload className="size-5" />
                            <span className="text-sm">Upload logo</span>
                        </button>
                    )}
                </div>
                <FieldDescription>
                    PNG or JPEG, up to 10 MB. You crop it to a square.
                </FieldDescription>
                <FieldError>{error}</FieldError>
            </Field>

            <LogoCropDialog
                source={source}
                open={cropOpen}
                onOpenChange={setCropOpen}
                onChooseAnother={logoUrl ? pickFile : undefined}
            />

            {logoUrl && (
                <Dialog open={viewOpen} onOpenChange={setViewOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Property logo</DialogTitle>
                        </DialogHeader>
                        <div className="flex aspect-square items-center justify-center overflow-hidden rounded-2xl border bg-muted">
                            <img
                                src={logoUrl}
                                alt="Property logo"
                                className="size-full object-contain"
                            />
                        </div>
                        <DialogFooter showCloseButton />
                    </DialogContent>
                </Dialog>
            )}

            <AlertDialog open={removeOpen} onOpenChange={setRemoveOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove the logo?</AlertDialogTitle>
                        <AlertDialogDescription>
                            PDFs will start the header with the property name.
                            You can upload a logo again later.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRemoveOpen(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
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
        </section>
    );
}

function TileAction({
    icon: Icon,
    label,
    loading = false,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    loading?: boolean;
    onClick: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon-sm"
                        aria-label={label}
                        disabled={loading}
                        focusableWhenDisabled
                        onClick={onClick}
                    />
                }
            >
                {loading ? <Spinner /> : <Icon />}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
