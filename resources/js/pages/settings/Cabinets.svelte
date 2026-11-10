<script module lang="ts">
    import { index } from '@/routes/cabinet-tokens';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Cabinets',
                href: index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import cabinetTokenRoutes from '@/routes/cabinet-tokens';
    import { edit as editProfile } from '@/routes/profile';
    import { localeNames } from '@/lib/i18n';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { m } from '@/paraglide/messages';
    import { getLocale, locales, setLocale } from '@/paraglide/runtime';

    type Cabinet = {
        id: number;
        name: string;
        plays: number;
        last_seen_at: string | null;
        disabled_at: string | null;
        revoked_at: string | null;
        created_at: string | null;
    };

    let {
        acceptedTerms,
        banned,
        cabinets,
        issued,
    }: {
        acceptedTerms: boolean;
        banned: boolean;
        cabinets: Cabinet[];
        issued: { id: number; name: string; token: string } | null;
    } = $props();

    let agreed = $state(false);

    // setLocale reloads the page, so these never go stale.
    const rules = [
        {
            title: m.cabinet_terms_one_title(),
            text: m.cabinet_terms_one_text(),
        },
        {
            title: m.cabinet_terms_share_title(),
            text: m.cabinet_terms_share_text(),
        },
        {
            title: m.cabinet_terms_terminate_title(),
            text: m.cabinet_terms_terminate_text(),
        },
        {
            title: m.cabinet_terms_liability_title(),
            text: m.cabinet_terms_liability_text(),
        },
    ];

    function formatDate(iso: string | null): string {
        return iso ? new Date(iso).toLocaleString() : '—';
    }
</script>

<AppHead title="Cabinets" />

{#if !acceptedTerms}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-background p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cabinet-terms-title"
        lang={getLocale()}
    >
        <div class="flex w-full max-w-2xl flex-col gap-5 py-8">
            <div
                class="flex flex-wrap items-center justify-end gap-1"
                role="group"
                aria-label={m.language()}
            >
                {#each locales as locale (locale)}
                    <Button
                        size="sm"
                        variant={locale === getLocale() ? 'secondary' : 'ghost'}
                        aria-pressed={locale === getLocale()}
                        lang={locale}
                        onclick={() => setLocale(locale)}
                        >{localeNames[locale]}</Button
                    >
                {/each}
            </div>
            <h1 id="cabinet-terms-title" class="text-2xl font-semibold">
                {m.cabinet_terms_title()}
            </h1>
            <p class="text-sm text-muted-foreground">
                {m.cabinet_terms_intro()}
            </p>
            <div
                class="rounded-md border-2 border-red-600 bg-red-600/10 p-4 text-base font-bold text-red-700 dark:border-red-500 dark:text-red-400"
            >
                <p>{m.cabinet_terms_money_purpose()}</p>
                <p class="mt-2 text-lg">
                    {m.cabinet_terms_money_forbidden()}
                </p>
                <p class="mt-2">{m.cabinet_terms_money_detail()}</p>
            </div>
            <ul class="list-disc space-y-3 pl-5 text-sm">
                {#each rules as rule (rule.title)}
                    <li>
                        <strong>{rule.title}</strong>
                        {rule.text}
                    </li>
                {/each}
                <li>{m.cabinet_terms_misuse()}</li>
            </ul>
            <Form
                {...cabinetTokenRoutes.terms.form(taikoRouteParam())}
                class="flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <label class="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            name="accept"
                            value="1"
                            class="mt-0.5 size-4"
                            bind:checked={agreed}
                        />
                        <span
                            >{m.cabinet_terms_checkbox()}
                            <strong>{m.cabinet_terms_checkbox_money()}</strong
                            ></span
                        >
                    </label>
                    <InputError message={errors.accept} />
                    <div class="flex flex-wrap gap-3">
                        <Button type="submit" disabled={!agreed || processing}
                            >{m.cabinet_terms_accept()}</Button
                        >
                        <Button asChild variant="outline">
                            {#snippet children(props)}
                                <Link
                                    href={editProfile(taikoRouteParam())}
                                    {...props}>{m.cabinet_terms_decline()}</Link
                                >
                            {/snippet}
                        </Button>
                    </div>
                {/snippet}
            </Form>
        </div>
    </div>
{:else}
    <h1 class="sr-only">Cabinets</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Cabinet tokens"
            description="One token per cabinet: put it in that cabinet's config.cfg as cabinet_token. A token is shown once, when you make it. Revoking one cuts that cabinet off for good."
        />

        {#if issued}
            <div
                class="flex flex-col gap-2 rounded-md border border-emerald-500/40 bg-emerald-500/10 p-4"
            >
                <div class="text-sm font-medium">
                    Token for {issued.name}: copy it now, it is not shown again.
                </div>
                <code
                    class="rounded bg-background px-2 py-1 text-sm break-all select-all"
                    >cabinet_token = {issued.token}</code
                >
            </div>
        {/if}

        {#if banned}
            <p class="text-sm text-red-600 dark:text-red-400">
                Your account is restricted: your cabinet tokens are off and you
                can't make new ones.
            </p>
        {:else}
            <Form
                {...cabinetTokenRoutes.store.form(taikoRouteParam())}
                class="flex flex-wrap items-end gap-3"
                options={{ preserveScroll: true }}
                resetOnSuccess
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-1 flex-col gap-1.5">
                        <Label for="cabinet-name">Cabinet name</Label>
                        <Input
                            id="cabinet-name"
                            name="name"
                            maxlength={100}
                            required
                            placeholder="My cab, left side"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <Button type="submit" disabled={processing}
                        >Make token</Button
                    >
                {/snippet}
            </Form>
        {/if}

        {#if cabinets.length === 0}
            <p class="text-sm text-muted-foreground">No cabinet tokens yet.</p>
        {:else}
            <ul class="divide-y rounded-md border">
                {#each cabinets as cabinet (cabinet.id)}
                    <li
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{cabinet.name}</span>
                                {#if cabinet.revoked_at}
                                    <Badge variant="destructive">Revoked</Badge>
                                {:else if cabinet.disabled_at}
                                    <Badge variant="secondary"
                                        >Disabled by staff</Badge
                                    >
                                {/if}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Made: {formatDate(cabinet.created_at)} · Last seen:
                                {formatDate(cabinet.last_seen_at)} · Plays:
                                {cabinet.plays}
                            </p>
                        </div>
                        {#if !cabinet.revoked_at}
                            <Form
                                {...cabinetTokenRoutes.revoke.form({
                                    ...taikoRouteParam(),
                                    cabinet: cabinet.id,
                                })}
                                options={{ preserveScroll: true }}
                                onBefore={() =>
                                    confirm(
                                        `Revoke ${cabinet.name}? It is cut off for good.`,
                                    )}
                            >
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}>Revoke</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                    </li>
                {/each}
            </ul>
        {/if}
    </div>
{/if}
