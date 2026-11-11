import { ArrowDown, ArrowUp, Banknote } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type BankAccount = App.Data.Catalogues.BankAccountData;

/** The bank account list: the Available switch first, arrows for the available accounts, then Edit. */
export function BankAccountTable({
    bankAccounts,
    onEdit,
    onMove,
    onToggleActive,
    togglingIds,
}: {
    bankAccounts: BankAccount[];
    onEdit: (bankAccount: BankAccount) => void;
    onMove: (from: number, to: number) => void;
    onToggleActive: (bankAccount: BankAccount, active: boolean) => void;
    togglingIds: Set<number>;
}) {
    const activeAccounts = bankAccounts.filter(
        (bankAccount) => bankAccount.active,
    );
    const activePositions = new Map(
        activeAccounts.map((bankAccount, position) => [
            bankAccount.id,
            position,
        ]),
    );

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Bank</TableHead>
                        <TableHead>Account name</TableHead>
                        <TableHead>Account number</TableHead>
                        <TableHead>SWIFT</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {bankAccounts.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6}>
                                <EmptyState
                                    icon={Banknote}
                                    title="No bank accounts yet."
                                    description="Add the accounts companies transfer to."
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {bankAccounts.map((bankAccount) => {
                        const activePosition = activePositions.get(
                            bankAccount.id,
                        );

                        return (
                            <TableRow key={bankAccount.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={bankAccount.active}
                                        disabled={togglingIds.has(
                                            bankAccount.id,
                                        )}
                                        aria-label={`Make ${bankAccount.bankName} ${bankAccount.accountNumber} ${bankAccount.active ? 'unavailable' : 'available'}`}
                                        onCheckedChange={(active) =>
                                            onToggleActive(bankAccount, active)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <span className="font-medium">
                                        {bankAccount.bankName}
                                    </span>
                                </TableCell>
                                <TableCell>{bankAccount.accountName}</TableCell>
                                <TableCell className="tabular-nums">
                                    {bankAccount.accountNumber}
                                </TableCell>
                                <TableCell>
                                    {bankAccount.swiftCode ?? (
                                        <span className="text-muted-foreground">
                                            None
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {activePosition !== undefined ? (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${bankAccount.bankName} up`}
                                                    disabled={
                                                        activePosition === 0
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            activePosition,
                                                            activePosition - 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${bankAccount.bankName} down`}
                                                    disabled={
                                                        activePosition ===
                                                        activeAccounts.length -
                                                            1
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            activePosition,
                                                            activePosition + 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </>
                                        ) : (
                                            <Badge variant="secondary">
                                                Inactive
                                            </Badge>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onEdit(bankAccount)}
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
