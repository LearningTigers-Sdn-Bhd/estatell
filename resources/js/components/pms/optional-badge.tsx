import { Badge } from '@/components/ui/badge';

/** Marks an optional field. Put it inside the field label, after the text. */
export function OptionalBadge() {
    return (
        <Badge variant="secondary" className="font-normal">
            Optional
        </Badge>
    );
}
