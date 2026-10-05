import { CardSection } from '@/components/pms/registration-card/card-section';
import { CardTextField } from '@/components/pms/registration-card/card-text-field';
import { SignatureField } from '@/components/pms/registration-card/signature-field';
import type { CardSectionProps } from '@/components/pms/registration-card/use-card-form';
import { FieldError, FieldGroup } from '@/components/ui/field';

/** The signer's name and the signature pad. */
export function SignSection({ form, word }: CardSectionProps) {
    const errors = form.errors as Record<string, string>;

    return (
        <CardSection title={word('Signature')}>
            <FieldGroup className="grid gap-4 sm:grid-cols-2">
                <CardTextField
                    form={form}
                    name="signer_name"
                    label={word('Name of signer')}
                    placeholder={word('Type your full name')}
                />
            </FieldGroup>
            <SignatureField
                onChange={(dataUrl) => form.setData('signature', dataUrl)}
                invalid={!!errors.signature}
                labels={{
                    title: word('Signature'),
                    clear: word('Clear'),
                    fullScreen: word('Full screen'),
                    done: word('Done'),
                    cancel: word('Cancel'),
                }}
            />
            <FieldError>{errors.signature}</FieldError>
        </CardSection>
    );
}
