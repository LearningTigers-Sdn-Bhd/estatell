import { Link } from '@inertiajs/react';
import { Check, ChevronsUpDown } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The switch menu after a crumb: the other records or tabs at the same level. The crumb itself stays a link.
 */
function CrumbSwitcher({
    title,
    siblings,
}: {
    title: string;
    siblings: NonNullable<BreadcrumbItemType['siblings']>;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        aria-label={`Switch ${siblings.label.toLowerCase()}, now ${title}`}
                    />
                }
            >
                <ChevronsUpDown aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-auto min-w-48">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{siblings.label}</DropdownMenuLabel>
                    {siblings.items.map((item, index) => (
                        <DropdownMenuItem
                            key={index}
                            render={<Link href={item.href} />}
                            aria-current={item.current ? 'page' : undefined}
                        >
                            <span className="truncate">{item.title}</span>
                            {item.current && (
                                <Check aria-hidden className="ml-auto" />
                            )}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb>
                    <BreadcrumbList>
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem className="gap-0.5">
                                        {isLast ? (
                                            <BreadcrumbPage>
                                                {item.title}
                                            </BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink
                                                render={
                                                    <Link href={item.href} />
                                                }
                                            >
                                                {item.title}
                                            </BreadcrumbLink>
                                        )}
                                        {item.siblings &&
                                            item.siblings.items.length > 1 && (
                                                <CrumbSwitcher
                                                    title={item.title}
                                                    siblings={item.siblings}
                                                />
                                            )}
                                    </BreadcrumbItem>
                                    {!isLast && <BreadcrumbSeparator />}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
