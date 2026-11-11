import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { SheetClose, SheetFooter } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { store, update } from '@/routes/settings/bank-accounts';

type BankAccount = App.Data.Catalogues.BankAccountData;

export type BankAccountFormData = {
    bank_name: string;
    account_name: string;
    account_number: string;
    swift_code: string;
    active: boolean;
};

export function bankAccountFormValues(
    bankAccount: BankAccount | null,
): BankAccountFormData {
    return {
        bank_name: bankAccount?.bankName ?? '',
        account_name: bankAccount?.accountName ?? '',
        account_number: bankAccount?.accountNumber ?? '',
        swift_code: bankAccount?.swiftCode ?? '',
        active: bankAccount?.active ?? true,
    };
}

/** The add and edit form of a bank account, inside the Sheet of Settings › Bank accounts. */
export function BankAccountForm({
    bankAccount,
    onDone,
}: {
    bankAccount: BankAccount | null;
    onDone: () => void;
}) {
    const form = useForm<BankAccountFormData>(
        bankAccountFormValues(bankAccount),
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (bankAccount) {
            form.put(update.url({ bankAccount: bankAccount.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field data-invalid={!!form.errors.bank_name}>
                    <FieldLabel htmlFor="bank-account-bank">
                        Bank name
                    </FieldLabel>
                    <Input
                        id="bank-account-bank"
                        placeholder="e.g. Maybank"
                        name="bank_name"
                        maxLength={100}
                        value={form.data.bank_name}
                        onChange={(event) =>
                            form.setData('bank_name', event.target.value)
                        }
                        aria-invalid={!!form.errors.bank_name}
                        autoFocus
                    />
                    <FieldError>{form.errors.bank_name}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.account_name}>
                    <FieldLabel htmlFor="bank-account-name">
                        Account name
                    </FieldLabel>
                    <Input
                        id="bank-account-name"
                        placeholder="e.g. Seaview Hospitality Sdn Bhd"
                        name="account_name"
                        maxLength={150}
                        value={form.data.account_name}
                        onChange={(event) =>
                            form.setData('account_name', event.target.value)
                        }
                        aria-invalid={!!form.errors.account_name}
                    />
                    <FieldError>{form.errors.account_name}</FieldError>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.account_number}>
                        <FieldLabel htmlFor="bank-account-number">
                            Account number
                        </FieldLabel>
                        <Input
                            id="bank-account-number"
                            placeholder="e.g. 5123 4567 8901"
                            name="account_number"
                            maxLength={34}
                            inputMode="numeric"
                            value={form.data.account_number}
                            onChange={(event) =>
                                form.setData(
                                    'account_number',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.account_number}
                        />
                        <FieldError>{form.errors.account_number}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.swift_code}>
                        <FieldLabel htmlFor="bank-account-swift">
                            SWIFT code (optional)
                        </FieldLabel>
                        <Input
                            id="bank-account-swift"
                            placeholder="e.g. MBBEMYKL"
                            name="swift_code"
                            maxLength={11}
                            value={form.data.swift_code}
                            onChange={(event) =>
                                form.setData(
                                    'swift_code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            aria-invalid={!!form.errors.swift_code}
                        />
                        <FieldDescription>
                            For agents paying from abroad.
                        </FieldDescription>
                        <FieldError>{form.errors.swift_code}</FieldError>
                    </Field>
                </div>
                <Field orientation="horizontal">
                    <Switch
                        id="bank-account-active"
                        name="active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="bank-account-active">
                            Available
                        </FieldLabel>
                        <FieldDescription>
                            Companies see this account in the portal. Turn it
                            off to hide the account without deleting it.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {bankAccount ? 'Save changes' : 'Add account'}
                </Button>
            </SheetFooter>
        </form>
    );
}
