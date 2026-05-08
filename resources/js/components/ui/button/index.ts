export { default as Button } from './Button.svelte';

export const buttonBase =
    'inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50';

export const buttonVariantStyles = {
    default: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    destructive: 'bg-destructive text-destructive-foreground shadow hover:bg-destructive/90',
    outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
    link: 'text-primary underline-offset-4 hover:underline',
} as const;

export const buttonSizeStyles = {
    default: 'h-9 px-4 py-2',
    sm: 'h-8 rounded-md px-3 text-xs',
    lg: 'h-10 rounded-md px-8',
    icon: 'h-9 w-9',
} as const;

export function buttonVariants(opts: {
    variant?: keyof typeof buttonVariantStyles;
    size?: keyof typeof buttonSizeStyles;
    class?: string;
} = {}): string {
    const { variant = 'default', size = 'default', class: extra = '' } = opts;
    return [buttonBase, buttonVariantStyles[variant], buttonSizeStyles[size], extra]
        .filter(Boolean)
        .join(' ');
}
