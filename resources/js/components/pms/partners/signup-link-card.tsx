import { Form } from '@inertiajs/react';
import { Check, Copy, Ellipsis, Link2, RefreshCw } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { regenerate } from '@/routes/partner/link';

/**
 * The ready-to-send text. It is built here because the link holds no secret the partner does not already have.
 */
function joinMessage(partnerName: string, signupUrl: string): string {
    return [
        `Hi! Set up your property on estatell with ${partnerName}.`,
        `Register here: ${signupUrl}`,
        "We'll help you get started once you've signed up.",
    ].join('\n');
}

/**
 * The partner's signup link: Copy link, Copy message and, in the menu, Regenerate link with a confirm.
 */
export function SignupLinkCard({
    partnerName,
    signupUrl,
}: {
    partnerName: string;
    signupUrl: string;
}) {
    const [copiedText, copy] = useClipboard();
    const [regenerating, setRegenerating] = useState(false);
    const message = joinMessage(partnerName, signupUrl);

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
                        <Button variant="outline" onClick={() => copy(message)}>
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
