<script module lang="ts">
    /** A player's own sanctions (Sanction::standingFor), newest first. */
    export type Standing = {
        id: number;
        type: string;
        label: string;
        note: string | null;
        created_at: string | null;
        expires_at: string | null;
        revoked_at: string | null;
        active: boolean;
    }[];
</script>

<script lang="ts">
    import ShieldAlert from 'lucide-svelte/icons/shield-alert';

    let { standing }: { standing: Standing } = $props();

    const restriction = $derived(standing.find((sanction) => sanction.active && sanction.type === 'ban'));
    const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    const date = (value: string | null): string => (value ? dateFormatter.format(new Date(value)) : '');

    function status(sanction: Standing[number]): string {
        if (sanction.revoked_at) {
            return `lifted ${date(sanction.revoked_at)}`;
        }
        if (!sanction.active) {
            return `ended ${date(sanction.expires_at)}`;
        }
        return sanction.expires_at ? `until ${date(sanction.expires_at)}` : 'permanent';
    }
</script>

{#if restriction}
    <div class="flex gap-3 rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm" role="alert">
        <ShieldAlert class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
        <div>
            <p class="font-semibold text-red-700 dark:text-red-300">
                Your account is restricted {restriction.expires_at ? `until ${date(restriction.expires_at)}` : 'permanently'}.
            </p>
            <p class="text-muted-foreground">
                You can keep playing, but your plays don't count and other players can't see you or your scores.
            </p>
            {#if restriction.note}
                <p class="mt-1">{restriction.note}</p>
            {/if}
        </div>
    </div>
{/if}

<details class="rounded-lg border bg-card px-4 py-3 text-sm">
    <summary class="cursor-pointer font-medium">
        Account standing: {standing.some((sanction) => sanction.active) ? 'sanctioned' : 'good'}
    </summary>
    {#if standing.length === 0}
        <p class="mt-2 text-muted-foreground">No sanctions on your account.</p>
    {:else}
        <ul class="mt-2 flex flex-col gap-2">
            {#each standing as sanction (sanction.id)}
                <li>
                    <span class="font-medium">{sanction.label}</span>
                    <span class="text-muted-foreground">· {date(sanction.created_at)} · {status(sanction)}</span>
                    {#if sanction.note}
                        <p class="text-muted-foreground">{sanction.note}</p>
                    {/if}
                </li>
            {/each}
        </ul>
    {/if}
</details>
