import { router, useHttp, usePage } from '@inertiajs/react';
import { Command as CommandPrimitive } from 'cmdk';
import { ArrowLeft, ArrowRight, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
    CommandShortcut,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
} from '@/components/ui/input-group';
import { Kbd } from '@/components/ui/kbd';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { commandSearch } from '@/routes';

type Result = App.Data.Navigation.CommandResultData;
type NavItem = App.Data.Navigation.NavItemData;
type Response = { results: Result[] };

export default function CommandPaletteDialog({
    onClose,
    returnFocus,
}: {
    onClose: () => void;
    returnFocus: RefObject<HTMLElement | null>;
}) {
    const { props } = usePage();
    const [query, setQuery] = useState('');
    const [selection, setSelection] = useState('');
    const [actionItem, setActionItem] = useState<Result | null>(null);
    const [destinations, setDestinations] = useState(false);
    const [loaded, setLoaded] = useState<{
        query: string;
        results: Result[];
    } | null>(null);
    const [failedQuery, setFailedQuery] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const cache = useRef(
        new Map<string, { expires: number; results: Result[] }>(),
    );
    const input = useRef<HTMLInputElement | null>(null);
    const { get, cancel, processing } = useHttp<
        Record<string, never>,
        Response
    >({});
    const term = query.trim().toLocaleLowerCase();
    const canSearchRecords =
        props.auth.user.is_admin === true ||
        props.auth.user.partner_id != null ||
        props.property?.status === 'live';

    const pages = useMemo(() => {
        const entries: { item: NavItem; context: string }[] = [];
        if (props.property?.status === 'live') {
            props.navigation?.contexts.forEach((context) =>
                context.groups.forEach((group) =>
                    group.items.forEach((item) =>
                        entries.push({
                            item,
                            context: `${context.label} › ${group.label}`,
                        }),
                    ),
                ),
            );
        }
        (props.adminNavigation ?? props.partnerNavigation ?? []).forEach(
            (item) => entries.push({ item, context: 'Workspace' }),
        );
        (props.accountNavigation ?? []).forEach((item) =>
            entries.push({ item, context: 'Account' }),
        );
        (props.commandShortcuts ?? []).forEach((item) =>
            entries.unshift({ item, context: 'Actions' }),
        );
        const seen = new Set<string>();
        return entries
            .filter(({ item }) => {
                if (item.action || seen.has(item.href)) return false;
                seen.add(item.href);
                return true;
            })
            .map(({ item, context }): Result => ({
                id: `page:${item.href}`,
                group: 'Pages',
                label: item.title,
                description: context,
                href: item.href,
                icon: item.icon,
                status: null,
                actions: [
                    {
                        ...item,
                        title: item.external ? 'Open in new tab' : 'Open',
                    },
                ],
            }));
    }, [
        props.navigation,
        props.adminNavigation,
        props.partnerNavigation,
        props.accountNavigation,
        props.commandShortcuts,
        props.property?.status,
    ]);

    useEffect(() => {
        if (term.length < 2 || !canSearchRecords || actionItem) return;
        let active = true;
        const timer = window.setTimeout(() => {
            const cached = cache.current.get(term);
            if (cached && cached.expires > Date.now()) {
                setLoaded({ query: term, results: cached.results });
                return;
            }
            void get(commandSearch.url({ query: { q: term } }), {
                onSuccess: (response) => {
                    if (!active) return;
                    if (cache.current.size >= 20) cache.current.clear();
                    cache.current.set(term, {
                        expires: Date.now() + 15_000,
                        results: response.results,
                    });
                    setLoaded({ query: term, results: response.results });
                    setFailedQuery(null);
                },
                onHttpException: () => {
                    if (active) setFailedQuery(term);
                },
                onNetworkError: () => {
                    if (active) setFailedQuery(term);
                },
                onError: () => {
                    if (active) setFailedQuery(term);
                },
            }).catch(() => {
                if (active) setFailedQuery(term);
            });
        }, 250);
        return () => {
            active = false;
            window.clearTimeout(timer);
            cancel();
        };
    }, [term, canSearchRecords, actionItem, attempt, get, cancel]);

    const local = pages.filter((page) =>
        `${page.label} ${page.description}`.toLocaleLowerCase().includes(term),
    );
    const records =
        loaded?.query === term && term.length >= 2 ? loaded.results : [];
    const results = [...records, ...local.slice(0, term ? 5 : 10)];
    const groups = [...new Set(results.map((result) => result.group))];
    const selected =
        results.find((result) => result.id === selection) ?? results[0];
    const failed = failedQuery === term;
    const waiting =
        canSearchRecords &&
        term.length >= 2 &&
        !failed &&
        loaded?.query !== term;

    function navigate(href: string, external = false) {
        onClose();
        if (external) window.open(href, '_blank', 'noopener,noreferrer');
        else router.visit(href);
    }

    function back() {
        if (destinations) setDestinations(false);
        else setActionItem(null);
        setSelection('');
        input.current?.focus();
    }

    const actions: NavItem[] = actionItem
        ? [
              {
                  title: 'Open',
                  href: actionItem.href,
                  icon: actionItem.icon,
                  action: null,
                  active: false,
                  external: false,
              },
              ...actionItem.actions,
          ]
              .filter(
                  (item) =>
                      item.action === (destinations ? 'destination' : null) ||
                      (!destinations && item.action === 'destinations'),
              )
              .filter(
                  (item, index, all) =>
                      all.findIndex(
                          (other) =>
                              other.href === item.href &&
                              other.action === item.action,
                      ) === index,
              )
        : [];

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="top-16 max-h-4/5 translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl"
                showCloseButton={false}
                initialFocus={input}
                finalFocus={returnFocus}
                onKeyDownCapture={(event) => {
                    if (event.key === 'Escape' && actionItem) {
                        event.preventDefault();
                        event.stopPropagation();
                        back();
                    }
                }}
            >
                <DialogTitle className="sr-only">
                    Search pages and records
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Search this workspace. Use arrow keys to move, Enter to open
                    and Tab to show result actions.
                </DialogDescription>
                <Command
                    shouldFilter={false}
                    value={selection}
                    onValueChange={setSelection}
                    className="gap-2 p-2"
                    onKeyDown={(event) => {
                        if (
                            event.key === 'Tab' &&
                            !event.shiftKey &&
                            !actionItem &&
                            selected &&
                            event.target === input.current
                        ) {
                            event.preventDefault();
                            setActionItem(selected);
                            setSelection('');
                        }
                    }}
                >
                    <div className="flex items-center gap-1">
                        {actionItem && (
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Back to search"
                                onClick={back}
                                className="shrink-0"
                            >
                                <ArrowLeft />
                            </Button>
                        )}
                        <InputGroup className="h-8! rounded-lg! border-input/30 bg-input/30 shadow-none!">
                            <CommandPrimitive.Input
                                data-slot="command-input"
                                ref={input}
                                value={actionItem ? '' : query}
                                maxLength={100}
                                readOnly={actionItem !== null}
                                onValueChange={(value) => {
                                    setQuery(value);
                                    setSelection('');
                                    setFailedQuery(null);
                                }}
                                aria-label={
                                    actionItem
                                        ? 'Result actions'
                                        : 'Search pages and records'
                                }
                                placeholder={
                                    actionItem
                                        ? actionItem.label
                                        : 'Search pages, guests, reservations, rooms…'
                                }
                                className="w-full text-sm outline-hidden disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputGroupAddon>
                                <Search className="size-4 shrink-0 opacity-50" />
                            </InputGroupAddon>
                            <InputGroupAddon align="inline-end">
                                <InputGroupButton
                                    aria-label="Close search"
                                    onClick={onClose}
                                >
                                    <X />
                                </InputGroupButton>
                            </InputGroupAddon>
                        </InputGroup>
                    </div>
                    <div className="min-h-0 overflow-hidden rounded-lg border border-border">
                        <CommandList
                            className="max-h-80"
                            aria-busy={processing || waiting}
                        >
                            {actionItem ? (
                                <CommandGroup
                                    heading={
                                        destinations
                                            ? 'View as owner and open…'
                                            : `Actions · ${actionItem.label}`
                                    }
                                >
                                    {actions.map((action) => (
                                        <CommandItem
                                            key={`${action.title}:${action.href}`}
                                            value={action.title}
                                            onSelect={() => {
                                                if (
                                                    action.action ===
                                                    'destinations'
                                                ) {
                                                    setDestinations(true);
                                                    setSelection('');
                                                } else
                                                    navigate(
                                                        action.href,
                                                        action.external,
                                                    );
                                            }}
                                        >
                                            <ArrowRight />
                                            <span>{action.title}</span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ) : (
                                <>
                                    {groups.map((group) => (
                                        <CommandGroup
                                            key={group}
                                            heading={group}
                                        >
                                            {results
                                                .filter(
                                                    (result) =>
                                                        result.group === group,
                                                )
                                                .map((result) => (
                                                    <CommandItem
                                                        key={result.id}
                                                        value={result.id}
                                                        onSelect={() =>
                                                            navigate(
                                                                result.href,
                                                                result
                                                                    .actions[0]
                                                                    ?.external,
                                                            )
                                                        }
                                                    >
                                                        <Search />
                                                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                            <span className="truncate">
                                                                {result.label}
                                                            </span>
                                                            <span className="truncate text-xs text-muted-foreground">
                                                                {
                                                                    result.description
                                                                }
                                                            </span>
                                                        </div>
                                                        {result.status && (
                                                            <StatusBadge
                                                                status={
                                                                    result.status
                                                                }
                                                                dot={false}
                                                            />
                                                        )}
                                                        <CommandShortcut>
                                                            ↵
                                                        </CommandShortcut>
                                                    </CommandItem>
                                                ))}
                                        </CommandGroup>
                                    ))}
                                    {!waiting && !failed && (
                                        <CommandEmpty>
                                            No results found. Try a name, code
                                            or reference.
                                        </CommandEmpty>
                                    )}
                                </>
                            )}
                        </CommandList>
                    </div>
                    {!actionItem && (
                        <div
                            role="status"
                            className="px-3 py-1 text-xs text-muted-foreground"
                        >
                            {failed ? (
                                <div className="flex items-center justify-between gap-2">
                                    <span>Search could not load.</span>
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        onClick={() => {
                                            setFailedQuery(null);
                                            setAttempt((value) => value + 1);
                                        }}
                                    >
                                        Retry
                                    </Button>
                                </div>
                            ) : waiting ? (
                                <span className="flex items-center gap-2">
                                    <Spinner />
                                    Searching…
                                </span>
                            ) : term.length < 2 && canSearchRecords ? (
                                'Type at least 2 characters to search records.'
                            ) : (
                                'Results are limited to this workspace and your permissions.'
                            )}
                        </div>
                    )}
                </Command>
                <Separator />
                <div className="flex flex-wrap items-center gap-3 px-3 py-2 text-xs text-muted-foreground">
                    <span>
                        <Kbd>↑↓</Kbd> Move
                    </span>
                    <span>
                        <Kbd>↵</Kbd> Open
                    </span>
                    {!actionItem && (
                        <span className="hidden sm:inline">
                            <Kbd>Tab</Kbd> Actions
                        </span>
                    )}
                    {!actionItem && (
                        <Button
                            variant="ghost"
                            size="xs"
                            disabled={!selected}
                            className="sm:hidden"
                            onClick={() => {
                                if (selected) {
                                    setActionItem(selected);
                                    setSelection('');
                                    input.current?.focus();
                                }
                            }}
                        >
                            Actions
                        </Button>
                    )}
                    <span>
                        <Kbd>Esc</Kbd> {actionItem ? 'Back' : 'Close'}
                    </span>
                </div>
            </DialogContent>
        </Dialog>
    );
}
