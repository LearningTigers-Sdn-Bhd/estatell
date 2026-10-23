import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

export type FilterOption = { value: string; label: string; count?: number };

/**
 * A filter that picks any number of items from a short list. Nothing picked means all. The trigger shows the label,
 * then the picked name or how many are picked.
 */
export function MultiFilter({
    label,
    options,
    value,
    onValueChange,
    className,
}: {
    label: string;
    options: FilterOption[];
    value: string[];
    onValueChange: (value: string[]) => void;
    className?: string;
}) {
    const picked = options.filter((option) => value.includes(option.value));
    const summary =
        picked.length === 0
            ? null
            : picked.length === 1
              ? picked[0].label
              : `${picked.length} selected`;

    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        className={className}
                    />
                }
            >
                {label}
                {summary && (
                    <span className="text-muted-foreground">{summary}</span>
                )}
                <ChevronDown aria-hidden />
            </PopoverTrigger>
            <PopoverContent align="start" className="w-56">
                {options.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing to pick yet.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {options.map((option) => {
                            const id = `${label}-${option.value}`;

                            return (
                                <li
                                    key={option.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={id}
                                        checked={value.includes(option.value)}
                                        onCheckedChange={(checked) =>
                                            onValueChange(
                                                checked
                                                    ? [...value, option.value]
                                                    : value.filter(
                                                          (item) =>
                                                              item !==
                                                              option.value,
                                                      ),
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={id}
                                        className="flex flex-1 justify-between font-normal"
                                    >
                                        {option.label}
                                        {option.count !== undefined && (
                                            <span className="text-muted-foreground tabular-nums">
                                                {option.count}
                                            </span>
                                        )}
                                    </Label>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {value.length > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="mt-2 w-full"
                        onClick={() => onValueChange([])}
                    >
                        Clear
                    </Button>
                )}
            </PopoverContent>
        </Popover>
    );
}
