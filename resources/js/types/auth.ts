import type { DanRank } from './dan-rank';

export type UserRole = 'admin' | 'moderator' | 'user';

export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    role: UserRole;
    presence_status: 'online' | 'invisible';
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    danRank?: DanRank | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
