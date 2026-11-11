import { Copy } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * One value a company types into its banking app, with a copy button: the label on the left, the value and the button
 * on the right. The button has a Copy tooltip and a copy shows a toast, like `CopyField`.
 */
export function CopyRow({ label, value }: { label: string; value: string }) {
    const [, copy] = useClipboard();

    async function copyValue() {
        if (await copy(value)) {
            toast.add({ title: `${label} copied.`, type: 'success' });
        }
    }

    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="flex min-w-0 items-center gap-1">
                <span className="truncate font-medium tabular-nums">
                    {value}
                </span>
                <Tooltip>
                    <TooltipTrigger
                        render={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Copy ${label.toLowerCase()}`}
                                onClick={copyValue}
                            />
                        }
                    >
                        <Copy />
                    </TooltipTrigger>
                    <TooltipContent>Copy</TooltipContent>
                </Tooltip>
            </span>
        </div>
    );
}

/**
 * One bank account as a card: the bank and the account name on top, then a divider across the card and the account
 * number and the SWIFT code (when set), each with a copy button. With `radio` the header is the label of that radio,
 * and the card shows when it is picked; the copy buttons never change the pick.
 */
export function BankAccountCard({
    account,
    radio,
    radioId,
}: {
    account: App.Data.Catalogues.BankAccountData;
    radio?: ReactNode;
    radioId?: string;
}) {
    const header = (
        <>
            {radio}
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="font-medium">{account.bankName}</span>
                <span className="text-muted-foreground">
                    {account.accountName}
                </span>
            </span>
        </>
    );

    return (
        <div className="flex flex-col overflow-hidden rounded-lg border bg-muted/50 text-sm has-focus-visible:border-ring has-focus-visible:ring-3 has-focus-visible:ring-ring/50 has-data-checked:border-primary/30 has-data-checked:bg-primary/5">
            {radio ? (
                <label
                    htmlFor={radioId}
                    className="flex cursor-pointer items-start gap-2 px-3 py-2"
                >
                    {header}
                </label>
            ) : (
                <div className="flex items-start gap-2 px-3 py-2">{header}</div>
            )}
            <div className="flex flex-col border-t px-3 py-1">
                <CopyRow label="Account no." value={account.accountNumber} />
                {account.swiftCode && (
                    <CopyRow label="SWIFT" value={account.swiftCode} />
                )}
            </div>
        </div>
    );
}

/**
 * The property's available bank accounts for a company to transfer to, one card each.
 */
export function BankAccountList({
    bankAccounts,
}: {
    bankAccounts: App.Data.Catalogues.BankAccountData[];
}) {
    return (
        <ul className="flex flex-col gap-2">
            {bankAccounts.map((account) => (
                <li key={account.id}>
                    <BankAccountCard account={account} />
                </li>
            ))}
        </ul>
    );
}
