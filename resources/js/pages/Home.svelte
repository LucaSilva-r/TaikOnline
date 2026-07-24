<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { buttonVariants } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { rankings } from '@/routes';
    import { create as createPlay } from '@/routes/play';

    const taikoParam = taikoRouteParam();
</script>

<AppHead title="Home" />

<section
    class="mx-auto flex w-full max-w-7xl flex-col items-center px-4 py-20 text-center"
>
    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
        Welcome to TaikOnline
    </h1>
    <p class="mt-4 max-w-2xl text-balance text-muted-foreground">
        Online play, rankings, and community for the rhythm cabinet.
    </p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <Link
            href={toUrl(createPlay(taikoParam))}
            class={buttonVariants({ variant: 'default' })}
        >
            Play on cabinet
        </Link>
        <Link
            href={toUrl(rankings(taikoParam))}
            class={buttonVariants({ variant: 'outline' })}
        >
            Browse Rankings
        </Link>
    </div>
</section>
