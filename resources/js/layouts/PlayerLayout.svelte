<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import TaikoVersionSelect from '@/components/TaikoVersionSelect.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { buttonVariants } from '@/components/ui/button';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Toaster } from '@/components/ui/sonner';
    import UserMenuContent from '@/components/UserMenuContent.svelte';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes/admin';
    import { community, home, login, rankings, register } from '@/routes';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const auth = $derived(page.props.auth);
    const isAdmin = $derived(auth?.user?.role === 'admin');
    const url = currentUrlState();
    const taikoParam = taikoRouteParam();

    const navItems = [
        { title: 'Home', href: home(taikoParam) },
        { title: 'Rankings', href: rankings(taikoParam) },
        { title: 'Community', href: community(taikoParam) },
    ];

    const activeStyles = 'text-foreground border-primary';
    const inactiveStyles =
        'text-muted-foreground border-transparent hover:text-foreground';
</script>

<div class="flex min-h-screen flex-col bg-background text-foreground">
    <header class="border-b border-border/60 bg-background/80 backdrop-blur">
        <div class="mx-auto flex h-14 w-full max-w-7xl items-center gap-6 px-4">
            <Link href={toUrl(home(taikoParam))} class="flex items-center gap-2">
                <AppLogoIcon class="size-6 fill-current" />
                <span class="font-semibold">TaikOnline</span>
            </Link>

            <nav class="flex h-full items-center gap-1">
                {#each navItems as item (item.href.url)}
                    {@const active = url.isCurrentUrl(item.href, url.currentUrl)}
                    <Link
                        href={toUrl(item.href)}
                        class="flex h-14 items-center border-b-2 px-3 text-sm font-medium transition {active
                            ? activeStyles
                            : inactiveStyles}"
                    >
                        {item.title}
                    </Link>
                {/each}
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <TaikoVersionSelect />

                {#if auth?.user}
                    {#if isAdmin}
                        <Link
                            href={toUrl(dashboard(taikoParam))}
                            class="{buttonVariants({ variant: 'outline', size: 'sm' })} gap-2"
                        >
                            <ShieldCheck class="size-4" />
                            Backend
                        </Link>
                    {/if}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            {#snippet children(props)}
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="relative size-9 rounded-full"
                                    onclick={props.onclick}
                                    aria-expanded={props['aria-expanded']}
                                    data-state={props['data-state']}
                                >
                                    <Avatar class="size-8">
                                        {#if auth.user?.avatar}
                                            <AvatarImage
                                                src={auth.user.avatar}
                                                alt={auth.user?.name}
                                            />
                                        {/if}
                                        <AvatarFallback
                                            class="bg-neutral-200 text-sm font-semibold dark:bg-neutral-700"
                                        >
                                            {getInitials(auth.user?.name ?? '')}
                                        </AvatarFallback>
                                    </Avatar>
                                </Button>
                            {/snippet}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                {:else}
                    <Link
                        href={toUrl(login())}
                        class={buttonVariants({ variant: 'ghost', size: 'sm' })}
                    >
                        Log in
                    </Link>
                    <Link
                        href={toUrl(register())}
                        class={buttonVariants({ variant: 'default', size: 'sm' })}
                    >
                        Sign up
                    </Link>
                {/if}
            </div>
        </div>
    </header>

    <main class="flex-1">
        {@render children?.()}
    </main>

    <Toaster />
</div>
