import { Copy } from 'lucide-react';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { toast } from '@/components/ui/toast';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';

/** A read-only value with a copy button, as on the General tab. */
export function CopyField({
    id,
    label,
    value,
    description,
}: {
    id: string;
    label: string;
    value: string;
    description?: string;
}) {
    const [, copy] = useClipboard();

    async function copyValue() {
        if (await copy(value)) {
            toast.add({ title: `${label} copied.`, type: 'success' });
        }
    }

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <InputGroup>
                <InputGroupInput
                    id={id}
                    value={value}
                    readOnly
                    className="font-mono"
                />
                <InputGroupAddon align="inline-end">
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <InputGroupButton
                                    size="icon-xs"
                                    aria-label={`Copy ${label.toLowerCase()}`}
                                    onClick={copyValue}
                                />
                            }
                        >
                            <Copy />
                        </TooltipTrigger>
                        <TooltipContent>Copy</TooltipContent>
                    </Tooltip>
                </InputGroupAddon>
            </InputGroup>
            {description && <FieldDescription>{description}</FieldDescription>}
        </Field>
    );
}
