import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * The property's available bank accounts for a company to transfer to: the bank, the account name, the number with a
 * copy button, and the SWIFT code when set. The booking's How to pay and the Statements slip form show it.
 */
export function BankAccountList({
    bankAccounts,
}: {
    bankAccounts: App.Data.Catalogues.BankAccountData[];
}) {
    const [copiedText, copy] = useClipboard();

    return (
        <ul className="grid gap-2 sm:grid-cols-2">
            {bankAccounts.map((account) => (
                <li
                    key={account.id}
                    className="flex flex-col gap-0.5 rounded-lg border bg-muted/50 px-3 py-2 text-sm"
                >
                    <span className="font-medium">{account.bankName}</span>
                    <span>{account.accountName}</span>
                    <span className="flex items-center gap-1">
                        <span className="tabular-nums">
                            {account.accountNumber}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Copy ${account.bankName} account number`}
                            onClick={() => copy(account.accountNumber)}
                        >
                            {copiedText === account.accountNumber ? (
                                <Check />
                            ) : (
                                <Copy />
                            )}
                        </Button>
                    </span>
                    {account.swiftCode && (
                        <span className="text-xs text-muted-foreground">
                            SWIFT {account.swiftCode}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}
