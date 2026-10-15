import { createElement } from 'react';
import type { ComponentProps, MouseEvent } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { AppearanceToggle } from './appearance-toggle';
import { TooltipProvider } from './ui/tooltip';

vi.mock('@/hooks/use-appearance', () => ({ useAppearance: vi.fn() }));

let activate: ComponentProps<'button'>['onClick'];

vi.mock('@/components/ui/button', () => ({
    Button: ({ onClick, ...props }: ComponentProps<'button'>) => {
        activate = onClick;
        return createElement('button', props);
    },
}));

test.each<[Appearance, Appearance, string, string]>([
    ['light', 'dark', 'Light', 'sun'],
    ['dark', 'system', 'Dark', 'moon'],
    ['system', 'light', 'System', 'monitor'],
])(
    'the %s button shows its preference and switches to %s',
    (appearance, next, label, icon) => {
        const updateAppearance = vi.fn();
        vi.mocked(useAppearance).mockReturnValue({
            appearance,
            resolvedAppearance: appearance === 'light' ? 'light' : 'dark',
            updateAppearance,
        });

        const html = renderToStaticMarkup(
            <TooltipProvider>
                <AppearanceToggle />
            </TooltipProvider>,
        );

        expect(html).toContain(`aria-label="Theme: ${label}. Switch to`);
        expect(html).toContain(`lucide-${icon}`);
        expect(updateAppearance).not.toHaveBeenCalled();
        expect(activate).toBeTypeOf('function');

        activate?.({
            nativeEvent: new Event('click'),
        } as MouseEvent<HTMLButtonElement>);

        expect(updateAppearance).toHaveBeenCalledExactlyOnceWith(next);
    },
);
