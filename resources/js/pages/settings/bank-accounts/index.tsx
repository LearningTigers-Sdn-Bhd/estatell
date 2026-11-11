import { Head, router } from '@inertiajs/react';
import { Banknote, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import {
    BankAccountForm,
    bankAccountFormValues,
} from '@/components/pms/settings/bank-account-form';
import { BankAccountTable } from '@/components/pms/settings/bank-account-table';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { index, reorder, update } from '@/routes/settings/bank-accounts';

type BankAccount = App.Data.Catalogues.BankAccountData;

export default function BankAccountsIndex({
    bankAccounts,
}: {
    bankAccounts: BankAccount[];
}) {
    const [editing, setEditing] = useState<BankAccount | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const sorted = [...bankAccounts].sort(
        (first, second) => Number(second.active) - Number(first.active),
    );

    const move = (from: number, to: number) => {
        const order = sorted
            .filter((bankAccount) => bankAccount.active)
            .map((bankAccount) => bankAccount.id);

        if (order[from] === undefined || order[to] === undefined) {
            return;
        }

        order.splice(to, 0, ...order.splice(from, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const toggleActive = (bankAccount: BankAccount, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(bankAccount.id);
                } else {
                    next.delete(bankAccount.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ bankAccounts: BankAccount[] }>((props) => ({
                bankAccounts: props.bankAccounts.map((account) =>
                    account.id === bankAccount.id
                        ? { ...account, active }
                        : account,
                ),
            }))
            .put(
                update.url({ bankAccount: bankAccount.id }),
                { ...bankAccountFormValues(bankAccount), active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    const addButton = (
        <Button onClick={() => setEditing(null)}>
            <Plus data-icon="inline-start" />
            Add account
        </Button>
    );

    return (
        <>
            <Head title="Bank accounts" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Bank accounts"
                    description="Companies see available accounts in the portal. Documents print them too."
                    actions={addButton}
                />

                {bankAccounts.length === 0 ? (
                    <EmptyState
                        icon={Banknote}
                        title="No bank accounts"
                        description="Add the accounts companies transfer to."
                        action={addButton}
                    />
                ) : (
                    <BankAccountTable
                        bankAccounts={sorted}
                        onEdit={setEditing}
                        onMove={move}
                        onToggleActive={toggleActive}
                        togglingIds={togglingIds}
                    />
                )}
            </div>

            <Sheet
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>
                            {editing
                                ? `Edit ${editing.bankName}`
                                : 'Add bank account'}
                        </SheetTitle>
                        <SheetDescription>
                            The account companies transfer to.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <BankAccountForm
                            key={editing?.id ?? 'new'}
                            bankAccount={editing}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

BankAccountsIndex.layout = {
    breadcrumbs: [{ title: 'Bank accounts', href: index() }],
};
