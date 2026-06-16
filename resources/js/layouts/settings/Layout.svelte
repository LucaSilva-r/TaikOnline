<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { Separator } from '@/components/ui/separator';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { edit as editAppearance } from '@/routes/appearance';
    import { index as indexCabinets } from '@/routes/cabinets';
    import { edit as editCostumes } from '@/routes/costumes';
    import { edit as editCustomize } from '@/routes/customize';
    import { edit as editGameSettings } from '@/routes/game-settings';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const sidebarNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(taikoRouteParam()),
        },
        {
            title: 'Security',
            href: editSecurity(taikoRouteParam()),
        },
        {
            title: 'Customization',
            href: editCustomize(taikoRouteParam()),
        },
        {
            title: 'Costumes',
            href: editCostumes(taikoRouteParam()),
        },
        {
            title: 'Game Settings',
            href: editGameSettings(taikoRouteParam()),
        },
        {
            title: 'Cabinets',
            href: indexCabinets(taikoRouteParam()),
        },
        {
            title: 'Appearance',
            href: editAppearance(taikoRouteParam()),
        },
    ];

    const url = currentUrlState();
</script>

<div class="px-4 py-6">
    <Heading
        title="Settings"
        description="Manage your profile and account settings"
    />

    <div class="flex flex-col lg:flex-row lg:space-x-12">
        <aside class="w-full max-w-xl lg:w-48">
            <nav
                class="flex flex-col space-y-1 space-x-0"
                aria-label="Settings"
            >
                {#each sidebarNavItems as item (toUrl(item.href))}
                    <Button
                        variant="ghost"
                        class="w-full justify-start {url.isCurrentUrl(
                            item.href,
                            url.currentUrl,
                        )
                            ? 'bg-muted'
                            : ''}"
                        asChild
                    >
                        {#snippet children(props)}
                            <Link href={toUrl(item.href)} class={props.class}>
                                {item.title}
                            </Link>
                        {/snippet}
                    </Button>
                {/each}
            </nav>
        </aside>

        <Separator class="my-6 lg:hidden" />

        <div class="flex-1 md:max-w-2xl">
            <section class="max-w-xl space-y-12">
                {@render children?.()}
            </section>
        </div>
    </div>
</div>
