import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /**
     * Translation key alternative to `title` for static `.layout` objects,
     * which cannot call `t()` at module scope. When present, the Breadcrumbs
     * component renders `t(titleKey)` instead.
     */
    titleKey?: string;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};
