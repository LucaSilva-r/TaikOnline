<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import Menu from 'lucide-svelte/icons/menu';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import SiteDisclaimer from '@/components/SiteDisclaimer.svelte';
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
    import {
        Sheet,
        SheetContent,
        SheetHeader,
        SheetTitle,
    } from '@/components/ui/sheet';
    import { Toaster } from '@/components/ui/sonner';
    import UserMenuContent from '@/components/UserMenuContent.svelte';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { community, home, login, rankings, register } from '@/routes';
    import { dashboard } from '@/routes/admin';
    import { show as boardShow } from '@/routes/board';
    import { create as createPlay } from '@/routes/play';
    import songsRoutes from '@/routes/songs';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const auth = $derived(page.props.auth);
    const isAdmin = $derived(auth?.user?.role === 'admin');
    const url = currentUrlState();
    const taikoParam = taikoRouteParam();
    let mobileMenuOpen = $state(false);

    const navItems = $derived([
        { title: 'Home', href: toUrl(home(taikoParam)), featured: false },
        {
            title: 'Play',
            href: toUrl(createPlay(taikoParam)),
            featured: true,
        },
        ...(auth?.user
            ? [
                  {
                      title: 'My Board',
                      href: toUrl(
                          boardShow({ ...taikoParam, user: auth.user.id }),
                      ),
                      featured: false,
                  },
              ]
            : []),
        {
            title: 'Rankings',
            href: toUrl(rankings(taikoParam)),
            featured: false,
        },
        {
            title: 'Songs',
            href: toUrl(songsRoutes.index(taikoParam)),
            featured: false,
        },
        {
            title: 'Community',
            href: toUrl(community(taikoParam)),
            featured: false,
        },
    ]);

    const activeStyles = 'text-foreground border-primary';
    const inactiveStyles =
        'text-muted-foreground border-transparent hover:text-foreground';
</script>

<div class="flex min-h-screen flex-col bg-background text-foreground">
    <Sheet bind:open={mobileMenuOpen}>
        <SheetContent side="left" class="w-[300px] p-6">
            <SheetTitle class="sr-only">Navigation menu</SheetTitle>
            <SheetHeader class="flex justify-start text-left">
                <div class="flex items-center gap-2">
                    <AppLogoIcon class="size-6 fill-current text-primary" />
                    <span class="font-semibold">TaikOnline</span>
                </div>
            </SheetHeader>

            <nav class="mt-6 grid gap-1">
                {#each navItems as item (item.href)}
                    {@const active = url.isCurrentUrl(
                        item.href,
                        url.currentUrl,
                    )}
                    <Link
                        href={item.href}
                        onclick={() => (mobileMenuOpen = false)}
                        class="rounded-md px-3 py-2 text-sm font-medium transition {item.featured
                            ? `bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 ${active ? 'ring-2 ring-ring ring-offset-2 ring-offset-background' : ''}`
                            : active
                              ? 'bg-accent text-foreground'
                              : 'text-muted-foreground hover:bg-accent hover:text-foreground'}"
                    >
                        {item.title}
                    </Link>
                {/each}
            </nav>
        </SheetContent>
    </Sheet>

    <div
        class="flex w-full items-center justify-center bg-red-600 px-4 py-2 text-center text-sm font-semibold text-white"
    >
        ⚠️ ALPHA — This platform is in early alpha. Your data can and WILL be
        deleted without warning.
    </div>

    <header
        class="relative z-50 border-b border-border/60 bg-background/80 backdrop-blur"
    >
        <div
            class="mx-auto flex h-14 w-full max-w-7xl items-center gap-2 px-4 sm:gap-4 lg:gap-6"
        >
            <div class="lg:hidden">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-9"
                    onclick={() => (mobileMenuOpen = true)}
                    aria-expanded={mobileMenuOpen}
                >
                    <Menu class="size-5" />
                    <span class="sr-only">Open navigation menu</span>
                </Button>
            </div>

            <Link
                href={toUrl(home(taikoParam))}
                class="flex min-w-0 items-center gap-2"
            >
                <AppLogoIcon class="size-6 fill-current text-primary" />
                <span class="hidden font-semibold sm:inline">TaikOnline</span>
            </Link>

            <nav class="hidden h-full items-center gap-1 lg:flex">
                {#each navItems as item (item.href)}
                    {@const active = url.isCurrentUrl(
                        item.href,
                        url.currentUrl,
                    )}
                    <Link
                        href={item.href}
                        class={item.featured
                            ? `my-auto flex h-8 items-center rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90 ${active ? 'ring-2 ring-ring ring-offset-2 ring-offset-background' : ''}`
                            : `flex h-14 items-center border-b-2 px-3 text-sm font-medium transition ${active ? activeStyles : inactiveStyles}`}
                    >
                        {item.title}
                    </Link>
                {/each}
            </nav>

            <div class="ml-auto flex min-w-0 items-center gap-2">
                <TaikoVersionSelect />

                {#if auth?.user}
                    {#if isAdmin}
                        <Link
                            href={toUrl(dashboard(taikoParam))}
                            class="{buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })} gap-2"
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
                                                class="bg-muted object-cover"
                                            />
                                        {:else}
                                            <AvatarFallback
                                                class="bg-neutral-200 text-sm font-semibold dark:bg-neutral-700"
                                            >
                                                {getInitials(
                                                    auth.user?.name ?? '',
                                                )}
                                            </AvatarFallback>
                                        {/if}
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
                        class={buttonVariants({
                            variant: 'default',
                            size: 'sm',
                        })}
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

    <SiteDisclaimer />

    <Toaster />
</div>
