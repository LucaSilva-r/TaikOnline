<script module lang="ts">
    import waddamburoNoticeIndexRoutes from '@/routes/admin/waddamburo-notices';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Waddamburo notices',
                href: waddamburoNoticeIndexRoutes.index(indexTaikoRouteParam()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import waddamburoNoticeRoutes from '@/routes/admin/waddamburo-notices';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Notice = {
        id: string;
        message: string;
        severity: string;
        ends_at: string | null;
        created_at: string | null;
        active: boolean;
    };
    let { notices, severities }: { notices: Notice[]; severities: string[] } =
        $props();

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const severityClass: Record<string, string> = {
        info: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        warning: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        critical: 'bg-red-500/15 text-red-600 dark:text-red-400',
    };

    function date(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }
</script>

<AppHead title="Waddamburo notices" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo notices</h1>
        <p class="text-sm text-muted-foreground">
            A notice reaches every running Waddamburo at once (a toast, then the
            F8 sidebar) and shows to the ones that start later until it ends.
            Ending or deleting it takes it off every client.
        </p>
    </div>

    <Form
        {...waddamburoNoticeRoutes.store.form(taikoRouteParam())}
        class="flex max-w-2xl flex-col gap-3 rounded-md border p-4"
        options={{ preserveScroll: true }}
        resetOnSuccess
    >
        {#snippet children({ errors, processing })}
            <div class="flex flex-col gap-1.5">
                <Label for="notice-message">Message</Label>
                <textarea
                    id="notice-message"
                    name="message"
                    rows="3"
                    maxlength="1000"
                    required
                    class="rounded-md border bg-background px-3 py-2 text-sm"
                    placeholder="Server maintenance tonight at 22:00 (about 30 minutes)."
                ></textarea>
                <InputError message={errors.message} />
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1.5">
                    <Label for="notice-severity">Severity</Label>
                    <select
                        id="notice-severity"
                        name="severity"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        {#each severities as severity (severity)}<option
                                value={severity}>{severity}</option
                            >{/each}
                    </select>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="notice-minutes">Show for (minutes)</Label>
                    <Input
                        id="notice-minutes"
                        name="minutes"
                        type="number"
                        min="1"
                        placeholder="until ended"
                        class="w-40"
                    />
                    <InputError message={errors.minutes} />
                </div>
                <Button type="submit" disabled={processing}
                    >Send to every client</Button
                >
            </div>
        {/snippet}
    </Form>

    <div class="overflow-x-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">Message</th>
                    <th class="px-3 py-2 font-medium">Severity</th>
                    <th class="px-3 py-2 font-medium">Sent</th>
                    <th class="px-3 py-2 font-medium">Ends</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                {#each notices as notice (notice.id)}
                    <tr
                        class="border-t {notice.active
                            ? ''
                            : 'text-muted-foreground'}"
                    >
                        <td class="max-w-md px-3 py-2 whitespace-pre-line"
                            >{notice.message}</td
                        >
                        <td class="px-3 py-2">
                            <Badge
                                variant="secondary"
                                class="border-0 {severityClass[
                                    notice.severity
                                ] ?? ''}">{notice.severity}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap"
                            >{date(notice.created_at)}</td
                        >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {notice.active
                                ? notice.ends_at
                                    ? date(notice.ends_at)
                                    : 'When ended'
                                : `Ended ${date(notice.ends_at)}`}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                {#if notice.active}
                                    <Form
                                        {...waddamburoNoticeRoutes.end.form({
                                            ...taikoRouteParam(),
                                            notice: Number(notice.id),
                                        })}
                                        options={{ preserveScroll: true }}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                                >End now</Button
                                            >
                                        {/snippet}
                                    </Form>
                                {/if}
                                <Form
                                    {...waddamburoNoticeRoutes.destroy.form({
                                        ...taikoRouteParam(),
                                        notice: Number(notice.id),
                                    })}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        confirm('Delete this notice?')}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}>Delete</Button
                                        >
                                    {/snippet}
                                </Form>
                            </div>
                        </td>
                    </tr>
                {:else}
                    <tr
                        ><td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                            >No notices yet.</td
                        ></tr
                    >
                {/each}
            </tbody>
        </table>
    </div>
</div>
