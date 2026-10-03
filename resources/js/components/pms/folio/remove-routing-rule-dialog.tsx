import { Form, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { KEEP_OPTIONS } from '@/components/pms/reservations/use-form-options';
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
import { Spinner } from '@/components/ui/spinner';
import { destroy } from '@/routes/reservations/routing-rules';

/**
 * Removes one Manual routing rule after a confirmation. A refusal comes back as an error toast.
 */
export function RemoveRoutingRuleDialog({
    reservationId,
    rule,
}: {
    reservationId: number;
    rule: App.Data.Folio.RoutingRuleRowData;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger
                render={
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Remove the rule for ${rule.charges}`}
                    />
                }
            >
                <Trash2 />
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Remove routing rule?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Later charges that match it ({rule.unit}, {rule.charges}
                        ) no longer go to {rule.target}. Charges already posted
                        stay where they are.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...destroy.form({
                        reservation: reservationId,
                        routingRule: rule.id,
                    })}
                    options={{ preserveScroll: true, except: KEEP_OPTIONS }}
                    onSuccess={() => {
                        setOpen(false);
                        router.reload({ only: ['routingRules'] });
                    }}
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
                                Remove rule
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
