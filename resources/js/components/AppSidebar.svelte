<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import Activity from 'lucide-svelte/icons/activity';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import FolderGit2 from 'lucide-svelte/icons/folder-git-2';
    import Home from 'lucide-svelte/icons/home';
    import Disc from 'lucide-svelte/icons/disc';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import Server from 'lucide-svelte/icons/server';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import Users from 'lucide-svelte/icons/users';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import adminRoute from '@/routes/admin';
    import adminDanDojo from '@/routes/admin/dan-dojo';
    import adminPlayers from '@/routes/admin/players';
    import adminSongs from '@/routes/admin/songs';
    import adminUsers from '@/routes/admin/users';
    const { dashboard, recentPlays, status } = adminRoute;
    import { home } from '@/routes';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const taikoParam = taikoRouteParam();

    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', href: dashboard(taikoParam), icon: LayoutGrid },
        { title: 'Players', href: adminPlayers.index(taikoParam), icon: Users },
        { title: 'Recent Plays', href: recentPlays(taikoParam), icon: Activity },
        { title: 'Songs', href: adminSongs.index(taikoParam), icon: Disc },
        { title: 'Dan Dojo', href: adminDanDojo.index(taikoParam), icon: BookOpen },
        { title: 'Server Status', href: status(taikoParam), icon: Server },
        { title: 'Users', href: adminUsers.index(taikoParam), icon: ShieldCheck },
    ];

    const footerNavItems: NavItem[] = [
        {
            title: 'Back to site',
            href: home({ taikoVersion: 'green' }),
            icon: Home,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard(taikoParam))}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter external={false} items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
