import type { Auth } from '@/types/auth';
import type {
    Direction,
    LocaleOption,
    Translations,
} from '@/types/localization';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            direction: Direction;
            locales: LocaleOption[];
            translations: Translations;
            [key: string]: unknown;
        };
    }
}
