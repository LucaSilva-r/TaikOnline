import type { Locale } from '@/paraglide/runtime';
import { defineCustomClientStrategy, getLocale } from '@/paraglide/runtime';

/** Each locale's name in its own language, for the language switcher. */
export const localeNames: Record<Locale, string> = {
    en: 'English',
    ja: '日本語',
    'zh-Hans': '简体中文',
    'zh-Hant': '繁體中文',
};

/**
 * The first of the browser's languages we have. Paraglide's own navigator strategy only matches
 * tags exactly or by base language, so zh-TW/zh-HK would miss zh-Hant.
 */
export function negotiateLocale(
    languages: readonly string[],
): Locale | undefined {
    for (const tag of languages) {
        const lower = tag.toLowerCase();
        if (lower.startsWith('ja')) {
            return 'ja';
        }
        if (lower.startsWith('zh')) {
            return /hant|-tw|-hk|-mo/.test(lower) ? 'zh-Hant' : 'zh-Hans';
        }
        if (lower.startsWith('en')) {
            return 'en';
        }
    }

    return undefined;
}

export function initializeI18n(): void {
    defineCustomClientStrategy('custom-navigator', {
        getLocale: () =>
            negotiateLocale(navigator.languages ?? [navigator.language]),
        // ponytail: the choice itself is saved by the localStorage strategy.
        setLocale: () => {},
    });
    document.documentElement.lang = getLocale();
}
