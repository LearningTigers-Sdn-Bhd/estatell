import {
    BedDouble,
    Bot,
    BookOpen,
    ChartNoAxesColumn,
    ConciergeBell,
    Globe,
    MapPin,
    Sparkles,
    ReceiptText,
    Users,
    Webhook,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { PanelHeading } from '@/components/pms/panel-heading';
import { AutoSaveStatus } from '@/components/pms/settings/auto-save-status';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAutoSave } from '@/hooks/use-auto-save';
import { update } from '@/routes/settings/ai-assistant/scopes';

type AiScope = App.Data.AiAssistant.AiScopeData;

/** Each scope's icon, before its label. */
const scopeIcons: Record<App.Enums.AiScope, LucideIcon> = {
    concierge: ConciergeBell,
    'booking-engine': Globe,
    staff: Users,
    api: Webhook,
    'room-quotes': BedDouble,
    'stay-details': ReceiptText,
    'guest-requests': Wrench,
    'daily-numbers': ChartNoAxesColumn,
    'how-to': BookOpen,
};

/**
 * One group of the hotel's AI assistant switches (places or capabilities): a table with the switch on the left, like
 * the Concierge guest actions. Only the scopes Estatell allows are listed.
 */
function AiScopeTable({
    heading,
    scopes,
    enabled,
    disabled,
    onChange,
}: {
    heading: string;
    scopes: AiScope[];
    enabled: Record<string, boolean>;
    disabled: boolean;
    onChange: (scope: App.Enums.AiScope, on: boolean) => void;
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-16">On</TableHead>
                        <TableHead>{heading}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {scopes.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={2}>
                                <EmptyState
                                    icon={Bot}
                                    title="Nothing here yet."
                                    description="Ask Estatell support to allow more for this property."
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {scopes.map((scope) => {
                        const Icon = scopeIcons[scope.scope];
                        const checked = enabled[scope.scope] ?? false;

                        return (
                            <TableRow key={scope.scope}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={checked}
                                        disabled={disabled}
                                        aria-label={`Turn ${scope.label} ${checked ? 'off' : 'on'}`}
                                        onCheckedChange={(next) =>
                                            onChange(scope.scope, next)
                                        }
                                    />
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <div className="flex min-w-0 items-start gap-2">
                                        <Icon
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <span className="font-medium">
                                                {scope.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {scope.description}
                                                {scope.note !== null &&
                                                    ` ${scope.note}`}
                                            </span>
                                        </div>
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

/**
 * The hotel's switches for one kind of scope (places or capabilities) Estatell allows. Each switch saves by itself
 * (`useAutoSave`), and every switch on the tab is sent with each save. Without the hotel's OpenAI key, the places are
 * off, so their switches are disabled; the status rail says why.
 */
export function AiScopeSwitches({
    kind,
    scopes,
    hasKey,
}: {
    kind: App.Enums.AiScopeKind;
    scopes: AiScope[];
    hasKey: boolean;
}) {
    const form = useAutoSave<Record<string, boolean>>(
        Object.fromEntries(scopes.map((scope) => [scope.scope, scope.enabled])),
        (value) => ({ url: update.url(), data: { scopes: value } }),
    );

    const place = kind === 'place';

    return (
        <section className="flex flex-col gap-3">
            <PanelHeading
                icon={place ? MapPin : Sparkles}
                title={place ? 'Where it answers' : 'What it can do'}
                description={
                    place
                        ? 'Turn on the places where guests and staff can chat with the assistant.'
                        : 'Answering questions and handing over to staff are always on. Turn on what else it may do.'
                }
                actions={<AutoSaveStatus saving={form.saving} />}
            />
            <AiScopeTable
                heading={place ? 'Place' : 'Capability'}
                scopes={scopes}
                enabled={form.value}
                disabled={place && !hasKey}
                onChange={(scope, on) =>
                    form.change({ ...form.value, [scope]: on })
                }
            />
        </section>
    );
}
