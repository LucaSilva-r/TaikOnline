import { page, router } from '@inertiajs/svelte';
import { setUrlDefaults } from '@/wayfinder';

export type TaikoVersionSupport = {
    favoriteFolder: boolean;
    favoriteLimit: number;
    costumeSlots: boolean;
    playOptionDefaults: boolean;
    toneDefault: boolean;
    rankingDifficulty: boolean;
    profilePublicity: boolean;
    difficultyFolderPresets: boolean;
};

export type TaikoVersionOption = {
    value: string;
    label: string;
    supports: TaikoVersionSupport;
};

const noFeatureSupport: TaikoVersionSupport = {
    favoriteFolder: false,
    favoriteLimit: 0,
    costumeSlots: false,
    playOptionDefaults: false,
    toneDefault: false,
    rankingDifficulty: false,
    profilePublicity: false,
    difficultyFolderPresets: false,
};

export type TaikoVersionContext = {
    scope: string;
    isAll: boolean;
    current: TaikoVersionOption | null;
    versions: TaikoVersionOption[];
    allowAll: boolean;
};

export type TaikoVersionAccent = {
    swatch: string;
    primary: string;
    primaryForeground: string;
    label: string;
    progress: string;
};

const fallbackScope = 'green';

const fallbackAccent: TaikoVersionAccent = {
    swatch: 'hsl(20 92% 52%)',
    primary: 'hsl(20 92% 46%)',
    primaryForeground: 'hsl(0 0% 100%)',
    label: 'hsl(15 86% 38%)',
    progress: '#ea580c',
};

const taikoVersionAccents: Record<string, TaikoVersionAccent> = {
    all: fallbackAccent,
    sorairo: {
        swatch: 'hsl(195 89% 52%)',
        primary: 'hsl(195 89% 42%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(198 89% 34%)',
        progress: '#0891b2',
    },
    momoiro: {
        swatch: 'hsl(334 86% 66%)',
        primary: 'hsl(334 78% 50%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(334 78% 38%)',
        progress: '#db2777',
    },
    kimidori: {
        swatch: 'hsl(88 70% 54%)',
        primary: 'hsl(88 70% 38%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(92 70% 28%)',
        progress: '#65a30d',
    },
    murasaki: {
        swatch: 'hsl(271 76% 64%)',
        primary: 'hsl(271 64% 48%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(271 64% 38%)',
        progress: '#9333ea',
    },
    white: {
        swatch: 'hsl(0 0% 100%)',
        primary: 'hsl(0 0% 24%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(0 0% 24%)',
        progress: '#525252',
    },
    red: {
        swatch: 'hsl(2 82% 58%)',
        primary: 'hsl(2 74% 48%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(2 74% 38%)',
        progress: '#dc2626',
    },
    yellow: {
        swatch: 'hsl(45 96% 56%)',
        primary: 'hsl(43 92% 48%)',
        primaryForeground: 'hsl(0 0% 9%)',
        label: 'hsl(38 92% 32%)',
        progress: '#eab308',
    },
    blue: {
        swatch: 'hsl(213 88% 58%)',
        primary: 'hsl(213 78% 48%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(213 78% 38%)',
        progress: '#2563eb',
    },
    green: {
        swatch: 'hsl(142 70% 48%)',
        primary: 'hsl(142 66% 36%)',
        primaryForeground: 'hsl(0 0% 100%)',
        label: 'hsl(142 66% 28%)',
        progress: '#16a34a',
    },
};

export function currentTaikoScope(): string {
    if (typeof window === 'undefined') {
        return fallbackScope;
    }

    const firstSegment = window.location.pathname.split('/').filter(Boolean)[0];

    return firstSegment || fallbackScope;
}

function taikoScopeFromUrl(url: string): string {
    const path = url.startsWith('http')
        ? new URL(url).pathname
        : new URL(url, window.location.origin).pathname;
    const firstSegment = path.split('/').filter(Boolean)[0];

    return firstSegment || fallbackScope;
}

export function taikoVersionAccent(scope: string): TaikoVersionAccent {
    return taikoVersionAccents[scope] ?? fallbackAccent;
}

export function taikoVersionAccentStyle(scope: string): string {
    const accent = taikoVersionAccent(scope);

    return `--version-swatch: ${accent.swatch}; --version-label: ${accent.label};`;
}

export function applyTaikoVersionAccent(
    scope: string = currentTaikoScope(),
): void {
    if (typeof document === 'undefined') {
        return;
    }

    const accent = taikoVersionAccent(scope);
    const root = document.documentElement;

    root.dataset.taikoVersion = scope;
    root.style.setProperty('--taiko-accent', accent.swatch);
    root.style.setProperty('--taiko-accent-label', accent.label);
    root.style.setProperty('--primary', accent.primary);
    root.style.setProperty('--primary-foreground', accent.primaryForeground);
    root.style.setProperty('--ring', accent.primary);
    root.style.setProperty(
        '--accent',
        'color-mix(in oklch, var(--taiko-accent) 12%, var(--background))',
    );
    root.style.setProperty('--accent-foreground', 'var(--foreground)');
    root.style.setProperty('--sidebar-primary', accent.primary);
    root.style.setProperty(
        '--sidebar-primary-foreground',
        accent.primaryForeground,
    );
    root.style.setProperty(
        '--sidebar-accent',
        'color-mix(in oklch, var(--taiko-accent) 15%, var(--sidebar-background))',
    );
    root.style.setProperty(
        '--sidebar-accent-foreground',
        'var(--sidebar-foreground)',
    );
    root.style.setProperty('--sidebar-ring', accent.primary);
}

export function initializeTaikoVersionAccent(): void {
    if (typeof window === 'undefined') {
        return;
    }

    applyTaikoVersionAccent();

    router.on('navigate', (event) => {
        applyTaikoVersionAccent(taikoScopeFromUrl(event.detail.page.url));
    });
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

/**
 * Feature-support flags for the currently scoped version, falling back to "no
 * support" when the context is missing (e.g. version-agnostic pages).
 */
export function currentTaikoSupports(): TaikoVersionSupport {
    return taikoVersionContext().current?.supports ?? noFeatureSupport;
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
