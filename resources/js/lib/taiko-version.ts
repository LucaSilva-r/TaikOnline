import { page } from '@inertiajs/svelte';
import { setUrlDefaults } from '@/wayfinder';

export type TaikoVersionOption = {
    value: string;
    label: string;
};

export type TaikoVersionContext = {
    scope: string;
    isAll: boolean;
    current: TaikoVersionOption | null;
    versions: TaikoVersionOption[];
    allowAll: boolean;
};

const fallbackScope = 'green';

export function currentTaikoScope(): string {
    if (typeof window === 'undefined') {
        return fallbackScope;
    }

    const firstSegment = window.location.pathname.split('/').filter(Boolean)[0];

    return firstSegment || fallbackScope;
}

export function taikoRouteParam(): { taikoVersion: string } {
    return { taikoVersion: currentTaikoScope() };
}

export function initializeTaikoRouteDefaults(): void {
    setUrlDefaults(() => taikoRouteParam());
}

export function taikoVersionContext(): TaikoVersionContext {
    return page.props.taikoVersion as TaikoVersionContext;
}

export function switchTaikoScope(targetScope: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    const segments = url.pathname.split('/').filter(Boolean);

    if (segments.length === 0) {
        segments.push(targetScope);
    } else {
        segments[0] = targetScope;
    }

    url.pathname = `/${segments.join('/')}`;
    window.location.href = `${url.pathname}${url.search}${url.hash}`;
}
