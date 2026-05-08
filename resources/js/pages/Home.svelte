<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { buttonVariants } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import { community, login, rankings, register } from '@/routes';

    let {
        canRegister = true,
    }: {
        canRegister?: boolean;
    } = $props();

    const auth = $derived(page.props.auth);
</script>

<AppHead title="Home" />

<section class="mx-auto flex w-full max-w-7xl flex-col items-center px-4 py-20 text-center">
    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
        Welcome to TaikOnline
    </h1>
    <p class="mt-4 max-w-2xl text-balance text-muted-foreground">
        Online play, rankings, and community for the rhythm cabinet.
    </p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        {#if auth?.user}
            <Link
                href={toUrl(rankings())}
                class={buttonVariants({ variant: 'default' })}
            >
                Browse Rankings
            </Link>
            <Link
                href={toUrl(community())}
                class={buttonVariants({ variant: 'outline' })}
            >
                Community
            </Link>
        {:else}
            <Link
                href={toUrl(login())}
                class={buttonVariants({ variant: 'default' })}
            >
                Log in
            </Link>
            {#if canRegister}
                <Link
                    href={toUrl(register())}
                    class={buttonVariants({ variant: 'outline' })}
                >
                    Sign up
                </Link>
            {/if}
        {/if}
    </div>
</section>
