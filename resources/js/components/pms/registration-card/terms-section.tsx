import { CheckCheck, ListX } from 'lucide-react';
import { CardSection } from '@/components/pms/registration-card/card-section';
import { TermsPreview } from '@/components/pms/registration-card/terms-preview';
import type { CardSectionProps } from '@/components/pms/registration-card/use-card-form';
import { Button } from '@/components/ui/button';

/** The terms with their tick boxes in place, a counter and Agree to all. */
export function TermsSection({ card, form, word }: CardSectionProps) {
    const total = card.checklist.length;
    const ticks = form.data.ticks;
    const allTicked = card.checklist.every((item) =>
        ticks.includes(item.position),
    );

    return (
        <CardSection
            title={word('Terms')}
            fill
            description={
                total > 1 &&
                word(':ticked of :total confirmed')
                    .replace(':ticked', String(ticks.length))
                    .replace(':total', String(total))
            }
            action={
                total > 1 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            form.setData(
                                'ticks',
                                allTicked
                                    ? []
                                    : card.checklist.map(
                                          (item) => item.position,
                                      ),
                            )
                        }
                    >
                        {allTicked ? (
                            <ListX data-icon="inline-start" />
                        ) : (
                            <CheckCheck data-icon="inline-start" />
                        )}
                        {word(allTicked ? 'Untick all' : 'Agree to all')}
                    </Button>
                )
            }
        >
            <div className="min-h-80 grow basis-0 overflow-y-auto rounded-lg border p-4">
                <TermsPreview
                    body={card.termsText}
                    values={{}}
                    ticks={ticks}
                    onToggle={(position, checked) =>
                        form.setData(
                            'ticks',
                            checked
                                ? [...ticks, position]
                                : ticks.filter((tick) => tick !== position),
                        )
                    }
                />
            </div>
        </CardSection>
    );
}
