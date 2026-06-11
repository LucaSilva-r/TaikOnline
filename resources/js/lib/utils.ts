import type { LinkComponentBaseProps } from '@inertiajs/core';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export type WithoutChildren<T> = T extends { children?: unknown }
    ? Omit<T, 'children'>
    : T;

export type WithElementRef<T, U extends HTMLElement = HTMLElement> = T & {
    ref?: U | null;
};

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(
    href: NonNullable<LinkComponentBaseProps['href']>,
): string {
    return typeof href === 'string' ? href : href.url;
}
