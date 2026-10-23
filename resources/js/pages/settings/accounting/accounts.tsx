import { Form, Head } from '@inertiajs/react';
import { Check, ListChecks, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AccountingShell } from '@/components/pms/accounting/accounting-shell';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { profile } from '@/routes/settings';
import { accounts, edit, mappings } from '@/routes/settings/accounting';

type Mapping = App.Data.Accounting.AccountingMappingData;
type View = 'all' | 'unmapped';

export default function AccountingAccounts({
    setup,
    mappings: rows,
}: {
    setup: App.Data.Accounting.AccountingSetupData;
    mappings: Mapping[];
}) {
    const [query, setQuery] = useState('');
    const [view, setView] = useState<View>('all');

    /** Groups keep the server's order. Filtering only hides rows, so every input stays in the form. */
    const groups = useMemo(() => {
        const byKind = new Map<string, Mapping[]>();

        for (const row of rows) {
            byKind.set(row.kindLabel, [
                ...(byKind.get(row.kindLabel) ?? []),
                row,
            ]);
        }

        return [...byKind.entries()];
    }, [rows]);

    const needle = query.trim().toLowerCase();
    const isVisible = (row: Mapping) =>
        (view === 'all' || !row.accountCode) &&
        (needle === '' ||
            row.code.toLowerCase().includes(needle) ||
            row.name.toLowerCase().includes(needle));
    const visibleCount = rows.filter(isVisible).length;
    const percent =
        setup.accountsTotal === 0
            ? 0
            : Math.round((setup.accountsMapped / setup.accountsTotal) * 100);

    return (
        <>
            <Head title="Accounting accounts" />
            <AccountingShell setup={setup} tab="accounts">
                {rows.length === 0 ? (
                    <EmptyState
                        icon={ListChecks}
                        title="No transaction codes yet"
                        description="Codes appear here once the property is set up."
                    />
                ) : (
                    <Form
                        {...mappings.form()}
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-4"
                    >
                        {({ isDirty, processing }) => (
                            <>
                                <Card size="sm">
                                    <CardContent className="flex flex-col gap-4">
                                        <Progress
                                            value={percent}
                                            aria-label="Codes mapped"
                                        >
                                            <span className="text-sm font-medium">
                                                {setup.accountsMapped} of{' '}
                                                {setup.accountsTotal} codes
                                                mapped
                                            </span>
                                        </Progress>
                                        <p className="text-sm text-muted-foreground">
                                            Type the account code from your
                                            accounting software next to each
                                            code. You only need accounts for the
                                            codes you use: a code with an amount
                                            and no account stops that day&apos;s
                                            push.
                                        </p>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <InputGroup className="sm:max-w-xs">
                                                <InputGroupAddon>
                                                    <Search aria-hidden />
                                                </InputGroupAddon>
                                                <InputGroupInput
                                                    type="search"
                                                    value={query}
                                                    onChange={(event) =>
                                                        setQuery(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="Search codes"
                                                    aria-label="Search codes"
                                                />
                                            </InputGroup>
                                            <ToggleGroup
                                                variant="outline"
                                                spacing={0}
                                                value={[view]}
                                                onValueChange={(value) =>
                                                    value[0] &&
                                                    setView(value[0] as View)
                                                }
                                                aria-label="Show"
                                            >
                                                <ToggleGroupItem value="all">
                                                    All
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="unmapped">
                                                    Not mapped
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                        </div>
                                    </CardContent>
                                </Card>

                                {groups.map(([kind, group]) => (
                                    <Card
                                        key={kind}
                                        className={
                                            group.some(isVisible)
                                                ? undefined
                                                : 'hidden'
                                        }
                                    >
                                        <CardContent className="flex flex-col gap-3">
                                            <h3 className="text-sm font-medium">
                                                {kind}{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    · {group.length}
                                                </span>
                                            </h3>
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Code
                                                        </TableHead>
                                                        <TableHead>
                                                            Name
                                                        </TableHead>
                                                        <TableHead className="w-56">
                                                            Account code
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {group.map((row) => (
                                                        <TableRow
                                                            key={row.id}
                                                            className={
                                                                isVisible(row)
                                                                    ? undefined
                                                                    : 'hidden'
                                                            }
                                                        >
                                                            <TableCell className="font-mono text-xs">
                                                                {row.code}
                                                            </TableCell>
                                                            <TableCell>
                                                                <span className="flex flex-col gap-1">
                                                                    <span className="flex items-center gap-2">
                                                                        {
                                                                            row.name
                                                                        }
                                                                        {row.accountCode ? (
                                                                            <Check
                                                                                className="size-3.5 text-success"
                                                                                aria-label="Mapped"
                                                                            />
                                                                        ) : (
                                                                            <Badge variant="outline">
                                                                                Not
                                                                                mapped
                                                                            </Badge>
                                                                        )}
                                                                    </span>
                                                                    {row.hint && (
                                                                        <span className="text-xs text-muted-foreground">
                                                                            {
                                                                                row.hint
                                                                            }
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    name={`accounts[${row.id}]`}
                                                                    defaultValue={
                                                                        row.accountCode ??
                                                                        ''
                                                                    }
                                                                    maxLength={
                                                                        50
                                                                    }
                                                                    aria-label={`Account code for ${row.name}`}
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </CardContent>
                                    </Card>
                                ))}

                                {visibleCount === 0 && (
                                    <EmptyState
                                        icon={Search}
                                        title="No codes match"
                                        description="Change the search or show all codes."
                                    />
                                )}

                                {isDirty && (
                                    <div className="sticky bottom-4 flex items-center justify-between gap-3 rounded-xl bg-card p-3 ring-1 ring-foreground/10">
                                        <p className="text-sm text-muted-foreground">
                                            You have unsaved account codes.
                                        </p>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && <Spinner />}
                                            Save accounts
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </AccountingShell>
        </>
    );
}

AccountingAccounts.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Accounting', href: edit() },
        { title: 'Accounts', href: accounts() },
    ],
};
