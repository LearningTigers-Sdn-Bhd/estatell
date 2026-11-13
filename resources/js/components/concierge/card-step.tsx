import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SignatureField } from '@/components/pms/registration-card/signature-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldError } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { sign } from '@/routes/concierge';

/** The registration card step: the guest reads the terms, agrees and signs with a finger. */
export function CardStep({
    stay,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
}) {
    const terms = stay.terms;
    const [agreed, setAgreed] = useState(false);
    const form = useForm({
        terms_version: terms?.version ?? 0,
        signature_png: '',
        locale: terms?.locale ?? '',
    });

    if (!terms) {
        return null;
    }

    return (
        <form
            className="flex flex-col gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(sign.url({ token: stay.token }));
            }}
        >
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold">Registration card</h2>
                <p className="text-sm text-pretty text-muted-foreground">
                    Read the terms, then sign below.
                </p>
            </div>

            <div className="max-h-72 overflow-y-auto rounded-lg border p-3 text-sm leading-relaxed whitespace-pre-wrap">
                {terms.text}
            </div>

            <div className="flex items-start gap-3">
                <Checkbox
                    id="agree"
                    checked={agreed}
                    onCheckedChange={(checked) => setAgreed(checked === true)}
                />
                <Label htmlFor="agree" className="text-sm leading-snug">
                    I have read and agree to the terms.
                </Label>
            </div>

            <SignatureField
                onChange={(dataUrl) => form.setData('signature_png', dataUrl)}
                invalid={!!form.errors.signature_png}
                labels={{
                    title: 'Signature',
                    clear: 'Clear',
                    fullScreen: 'Full screen',
                    done: 'Done',
                    cancel: 'Cancel',
                }}
            />
            <FieldError>{form.errors.signature_png}</FieldError>

            <Button
                type="submit"
                size="lg"
                className="h-12 w-full rounded-lg"
                disabled={
                    form.processing || !agreed || form.data.signature_png === ''
                }
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Sign and continue
            </Button>
        </form>
    );
}
