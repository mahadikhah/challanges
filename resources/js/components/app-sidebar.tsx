import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Coins,
    FolderGit2,
    Image,
    LayoutGrid,
    Mail,
    Trophy,
    Users,
    Wrench,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as adminChallenges } from '@/routes/admin/challenges';
import { index as adminInvites } from '@/routes/admin/invites';
import { index as adminPayments } from '@/routes/admin/payments';
import { index as adminReviews } from '@/routes/admin/reviews';
import { index as adminSettings } from '@/routes/admin/settings';
import { index as adminUsers } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const { t, isRtl } = useTranslation();

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    // The admin links are offered, not relied on — the panel re-checks
    // `is_admin` server-side on every request.
    if (auth.user.is_admin) {
        mainNavItems.push(
            {
                title: t('admin.users.title'),
                href: adminUsers.url(),
                icon: Users,
            },
            {
                title: t('admin.challenges.title'),
                href: adminChallenges.url(),
                icon: Trophy,
            },
            {
                title: t('admin.reviews.title'),
                href: adminReviews.url(),
                icon: Image,
            },
            {
                title: t('admin.payments.title'),
                href: adminPayments.url(),
                icon: Coins,
            },
            {
                title: t('admin.invites.title'),
                href: adminInvites.url(),
                icon: Mail,
            },
            {
                title: t('admin.settings.title'),
                href: adminSettings.url(),
                icon: Wrench,
            },
        );
    }

    return (
        // The sidebar component is side-aware: handing it the start side of
        // the active direction mirrors the panel, its border, its mobile
        // sheet and the trigger chevron in one move.
        <Sidebar
            collapsible="icon"
            variant="inset"
            side={isRtl ? 'right' : 'left'}
        >
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
