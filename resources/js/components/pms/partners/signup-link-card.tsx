import { Form } from '@inertiajs/react';
import { Check, Copy, Ellipsis, Link2, Pencil, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { SignupMessagePreviewDialog } from '@/components/pms/partners/signup-message-preview-dialog';
import { SignupMessageDialog } from '@/components/pms/partners/signup-message-dialog';
import { fillSignupMessage } from '@/components/pms/partners/signup-message';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import { useClipboard } from '@/hooks/use-clipboard';
import { regenerate } from '@/routes/partner/link';

/**
 * The partner's signup link: Copy link, Copy message and, in the menu, Edit message and Regenerate link with a
 * confirm.
 */
export function SignupLinkCard({
    partnerName,
    signupUrl,
    messageTemplate,
    defaultMessageTemplate,
}: {
    partnerName: string;
    signupUrl: string;
    messageTemplate: string;
    defaultMessageTemplate: string;
}) {
    const [copiedText, copy] = useClipboard();
    const [regenerating, setRegenerating] = useState(false);
    const [editingMessage, setEditingMessage] = useState(false);
    const [previewingMessage, setPreviewingMessage] = useState(false);
    const message = fillSignupMessage(messageTemplate, partnerName, signupUrl);

    const copyMessage = async () => {
        toast.add(
            (await copy(message))
                ? { title: 'Message copied', type: 'success' }
                : { title: 'The message could not be copied', type: 'error' },
        );
    };

    return (
        <Card>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="text-sm font-medium">
                            Your signup link
                        </h2>
                        <p className="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
                            <Link2 className="size-4 shrink-0" />
                            <span className="truncate tabular-nums">
                                {signupUrl}
                            </span>
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            onClick={() => copy(signupUrl)}
                        >
                            {copiedText === signupUrl ? (
                                <Check data-icon="inline-start" />
                            ) : (
                                <Copy data-icon="inline-start" />
                            )}
                            {copiedText === signupUrl ? 'Copied' : 'Copy link'}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                void copyMessage();
                                setPreviewingMessage(true);
                            }}
                        >
                            {copiedText === message ? (
                                <Check data-icon="inline-start" />
                            ) : (
                                <Copy data-icon="inline-start" />
                            )}
                            {copiedText === message ? 'Copied' : 'Copy message'}
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                render={<Button variant="ghost" size="icon" />}
                                aria-label="Signup link actions"
                            >
                                <Ellipsis />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem
                                    onClick={() => setEditingMessage(true)}
                                >
                                    <Pencil />
                                    Edit message
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() => setRegenerating(true)}
                                >
                                    <RefreshCw />
                                    Regenerate link
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
                <p className="text-sm text-muted-foreground">
                    Properties that register with this link appear here.
                </p>
            </CardContent>

            <SignupMessagePreviewDialog
                open={previewingMessage}
                onOpenChange={setPreviewingMessage}
                message={message}
                copied={copiedText === message}
                onCopy={() => void copyMessage()}
                onEdit={() => {
                    setPreviewingMessage(false);
                    setEditingMessage(true);
                }}
            />

            <SignupMessageDialog
                open={editingMessage}
                onOpenChange={setEditingMessage}
                partnerName={partnerName}
                signupUrl={signupUrl}
                template={messageTemplate}
                defaultTemplate={defaultMessageTemplate}
            />

            <AlertDialog open={regenerating} onOpenChange={setRegenerating}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Regenerate your signup link?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The old link stops working at once.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...regenerate.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setRegenerating(false)}
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
                                    Regenerate link
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}
