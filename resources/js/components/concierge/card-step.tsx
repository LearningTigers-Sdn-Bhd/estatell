import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, FileCheck2, PenLine } from 'lucide-react';
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
            className="concierge-registration-form grid items-start gap-8 lg:grid-cols-5 lg:gap-10"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(sign.url({ token: stay.token }));
            }}
        >
            <section
                className="flex min-w-0 flex-col gap-5 lg:col-span-3"
                aria-labelledby="registration-terms-title"
            >
                <div className="flex items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-primary">
                        <FileCheck2 className="size-5" aria-hidden />
                    </span>
                    <div className="flex flex-col gap-1">
                        <h2
                            id="registration-terms-title"
                            className="text-xl font-semibold"
                        >
                            Review the terms
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Read the registration terms before signing.
                        </p>
                    </div>
                </div>
                <div
                    role="region"
                    aria-label="Registration terms"
                    tabIndex={0}
                    className="max-h-96 overflow-y-auto rounded-2xl border border-border/40 bg-card p-5 text-sm leading-7 whitespace-pre-wrap outline-none focus-visible:ring-2 focus-visible:ring-primary sm:p-6"
                >
                    {terms.text}
                </div>
            </section>
            <section
                className="flex min-w-0 flex-col gap-6 rounded-2xl border border-border/40 bg-card p-5 sm:p-6 lg:col-span-2"
                aria-labelledby="registration-signature-title"
            >
                <div className="flex flex-col gap-2">
                    <h2
                        id="registration-signature-title"
                        className="flex items-center gap-2 text-xl font-semibold"
                    >
                        <PenLine className="size-5 text-primary" aria-hidden />
                        Sign your registration
                    </h2>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Use your finger or mouse to sign in the box. You can
                        open a larger signing area with the full-screen button.
                    </p>
                </div>
                <div className="flex items-start gap-3 rounded-xl bg-muted/40 p-4">
                    <Checkbox
                        id="agree"
                        checked={agreed}
                        onCheckedChange={(checked) =>
                            setAgreed(checked === true)
                        }
                    />
                    <Label htmlFor="agree" className="text-sm leading-relaxed">
                        I have read and agree to the terms.
                    </Label>
                </div>
                <SignatureField
                    onChange={(dataUrl) =>
                        form.setData('signature_png', dataUrl)
                    }
                    invalid={!!form.errors.signature_png}
                    labels={{
                        title: 'Your signature',
                        clear: 'Clear',
                        fullScreen: 'Full screen',
                        done: 'Done',
                        cancel: 'Cancel',
                    }}
                />
                <FieldError>{form.errors.signature_png}</FieldError>
                <FieldError>
                    {form.errors.terms_version ?? form.errors.locale}
                </FieldError>
                <Button
                    type="submit"
                    size="lg"
                    className="min-h-12 w-full"
                    disabled={
                        form.processing ||
                        !agreed ||
                        form.data.signature_png === ''
                    }
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Sign and continue
                    <ArrowRight data-icon="inline-end" aria-hidden />
                </Button>
            </section>
        </form>
    );
}
