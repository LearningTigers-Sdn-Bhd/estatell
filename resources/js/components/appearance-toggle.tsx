import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';

const modes = {
    light: { icon: Sun, label: 'Light', next: 'dark' },
    dark: { icon: Moon, label: 'Dark', next: 'system' },
    system: { icon: Monitor, label: 'System', next: 'light' },
} as const;

export function AppearanceToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const mode = modes[appearance];
    const Icon = mode.icon;
    const label = `Theme: ${mode.label}. Switch to ${modes[mode.next].label}.`;

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={label}
                        onClick={() => updateAppearance(mode.next)}
                    />
                }
            >
                <Icon />
            </TooltipTrigger>
            <TooltipContent side="bottom">{label}</TooltipContent>
        </Tooltip>
    );
}
