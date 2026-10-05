import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';

const placeholderPattern = /\{\{\s*([a-z_]+)\s*\}\}/g;
const taskPattern = /^\s*[-*+]\s+\[[ xX]\]\s+(.+?)\s*$/;
const bulletPattern = /^\s*[-*+]\s+(.+?)\s*$/;
const numberedPattern = /^\s*\d+[.)]\s+(.+?)\s*$/;

/** Fills `{{name}}` with the server's values. A name the server did not send stays as written. */
export function fillPlaceholders(
    body: string,
    values: Record<string, string>,
): string {
    return body.replace(
        placeholderPattern,
        (match, name: string) => values[name] ?? match,
    );
}

/** How many tick boxes a terms text makes. The server allows at most 10. */
export function countChecklistItems(body: string): number {
    return body.split('\n').filter((line) => taskPattern.test(line)).length;
}

/** Bold and italic only. Anything else shows as typed, and nothing is ever injected as HTML. */
function inline(text: string): ReactNode[] {
    return text.split(/(\*\*[^*]+\*\*|\*[^*]+\*)/g).map((part, index) => {
        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            return <strong key={index}>{part.slice(2, -2)}</strong>;
        }

        if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
            return <em key={index}>{part.slice(1, -1)}</em>;
        }

        return <Fragment key={index}>{part}</Fragment>;
    });
}

type Block =
    | { type: 'heading'; level: number; text: string }
    | { type: 'paragraph'; text: string }
    | { type: 'list'; ordered: boolean; items: string[] }
    | { type: 'tasks'; items: string[] };

function parse(body: string): Block[] {
    const blocks: Block[] = [];

    for (const line of body.split('\n')) {
        const last = blocks[blocks.length - 1];
        const heading = /^(#{1,3})\s+(.+?)\s*$/.exec(line);
        const task = taskPattern.exec(line);
        const bullet = bulletPattern.exec(line);
        const numbered = numberedPattern.exec(line);

        if (line.trim() === '') {
            blocks.push({ type: 'paragraph', text: '' });
        } else if (heading) {
            blocks.push({
                type: 'heading',
                level: heading[1].length,
                text: heading[2],
            });
        } else if (task) {
            if (last?.type === 'tasks') {
                last.items.push(task[1]);
            } else {
                blocks.push({ type: 'tasks', items: [task[1]] });
            }
        } else if (bullet || numbered) {
            const ordered = !bullet;
            const text = (bullet ?? numbered)![1];

            if (last?.type === 'list' && last.ordered === ordered) {
                last.items.push(text);
            } else {
                blocks.push({ type: 'list', ordered, items: [text] });
            }
        } else if (last?.type === 'paragraph' && last.text !== '') {
            last.text += ` ${line.trim()}`;
        } else if (last?.type === 'paragraph') {
            last.text = line.trim();
        } else {
            blocks.push({ type: 'paragraph', text: line.trim() });
        }
    }

    return blocks.filter((block) => block.type !== 'paragraph' || block.text);
}

/**
 * The terms as a guest sees them: the text, then one tick box per checklist item.
 * Drawn in the browser from the editor text, so it follows every keystroke.
 */
export function TermsPreview({
    body,
    values,
}: {
    body: string;
    values: Record<string, string>;
}) {
    const blocks = parse(fillPlaceholders(body, values));

    return (
        <div className="flex flex-col gap-3 text-sm">
            {blocks.map((block, index) => {
                if (block.type === 'heading') {
                    return block.level === 1 ? (
                        <h4 key={index} className="text-base font-semibold">
                            {inline(block.text)}
                        </h4>
                    ) : (
                        <h5 key={index} className="mt-1 font-semibold">
                            {inline(block.text)}
                        </h5>
                    );
                }

                if (block.type === 'paragraph') {
                    return <p key={index}>{inline(block.text)}</p>;
                }

                if (block.type === 'tasks') {
                    return (
                        <ul key={index} className="flex flex-col gap-2">
                            {block.items.map((item, position) => (
                                <li key={position}>
                                    <label className="flex items-start gap-2">
                                        <Checkbox
                                            disabled
                                            aria-label={item}
                                            className="mt-0.5"
                                        />
                                        <span>{inline(item)}</span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    );
                }

                const List = block.ordered ? 'ol' : 'ul';

                return (
                    <List
                        key={index}
                        className={
                            block.ordered
                                ? 'ml-5 list-decimal'
                                : 'ml-5 list-disc'
                        }
                    >
                        {block.items.map((item, position) => (
                            <li key={position}>{inline(item)}</li>
                        ))}
                    </List>
                );
            })}
        </div>
    );
}
