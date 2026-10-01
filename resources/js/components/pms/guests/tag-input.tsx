import { X } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    id: string;
    value: string[];
    onChange: (value: string[]) => void;
    /** Names of the property's existing tags, offered as one-click additions. */
    suggestions: string[];
    invalid?: boolean;
};

const MAX_LENGTH = 50;

/**
 * Chips for a guest's tags. Type a name and press Enter to add it, or click an existing tag.
 * A new name creates the tag when the guest is saved.
 */
export function TagInput({ id, value, onChange, suggestions, invalid }: Props) {
    const [draft, setDraft] = useState('');

    const has = (name: string) =>
        value.some((tag) => tag.toLowerCase() === name.toLowerCase());

    const add = (raw: string) => {
        const name = raw.trim().slice(0, MAX_LENGTH);

        if (name !== '' && !has(name)) {
            onChange([...value, name]);
        }

        setDraft('');
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add(draft);
        } else if (event.key === 'Backspace' && draft === '' && value.length) {
            onChange(value.slice(0, -1));
        }
    };

    const remaining = suggestions.filter((name) => !has(name));

    return (
        <div className="flex flex-col gap-2">
            <Input
                id={id}
                value={draft}
                maxLength={MAX_LENGTH}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={onKeyDown}
                onBlur={() => add(draft)}
                placeholder="Type a tag and press Enter"
                aria-invalid={invalid}
            />
            {value.length > 0 && (
                <ul
                    className="flex flex-wrap gap-1.5"
                    aria-label="Selected tags"
                >
                    {value.map((tag) => (
                        <li key={tag}>
                            <Badge variant="secondary">
                                {tag}
                                <button
                                    type="button"
                                    aria-label={`Remove ${tag}`}
                                    className="-me-1 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    onClick={() =>
                                        onChange(value.filter((t) => t !== tag))
                                    }
                                >
                                    <X aria-hidden />
                                </button>
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}
            {remaining.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-muted-foreground">
                        Existing:
                    </span>
                    {remaining.map((name) => (
                        <Button
                            key={name}
                            type="button"
                            size="xs"
                            variant="outline"
                            onClick={() => add(name)}
                        >
                            {name}
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}
