<script module lang="ts">
    type TurnstileApi = {
        render: (
            container: HTMLElement,
            options: Record<string, unknown>,
        ) => string;
        reset: (widgetId: string) => void;
        remove: (widgetId: string) => void;
    };

    let loader: Promise<TurnstileApi> | null = null;

    function loadTurnstile(): Promise<TurnstileApi> {
        loader ??= new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src =
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.onload = () =>
                resolve(
                    (window as unknown as { turnstile: TurnstileApi })
                        .turnstile,
                );
            script.onerror = () => {
                loader = null;
                reject(new Error('Turnstile failed to load'));
            };
            document.head.appendChild(script);
        });

        return loader;
    }
</script>

<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import InputError from '@/components/InputError.svelte';

    // Inside a <Form>: Turnstile adds a hidden cf-turnstile-response input, sent with the form.
    let { error = '' }: { error?: string } = $props();

    const siteKey = page.props.turnstileSiteKey;
    let container: HTMLDivElement | undefined = $state();
    let api: TurnstileApi | undefined;
    let widgetId: string | undefined;

    /** Tokens are single-use: call after every submission so a retry gets a fresh one. */
    export function reset(): void {
        if (api && widgetId) {
            api.reset(widgetId);
        }
    }

    onMount(() => {
        if (!siteKey || !container) {
            return;
        }

        let disposed = false;

        loadTurnstile().then((turnstile) => {
            if (disposed || !container) {
                return;
            }

            api = turnstile;
            widgetId = turnstile.render(container, {
                sitekey: siteKey,
                theme: 'auto',
                size: 'flexible',
            });
        });

        return () => {
            disposed = true;

            if (api && widgetId) {
                api.remove(widgetId);
            }
        };
    });
</script>

{#if siteKey}
    <div class="grid gap-2">
        <div bind:this={container}></div>
        <InputError message={error} />
    </div>
{/if}
