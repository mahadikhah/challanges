import { useTranslation } from '@/hooks/use-translation';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    // Pages pass translation keys through their static `.layout` object —
    // that object cannot call `t()` at module scope, so the resolution
    // happens here, inside the Inertia render tree.
    const { t } = useTranslation();

    return (
        <AuthLayoutTemplate title={t(title)} description={t(description)}>
            {children}
        </AuthLayoutTemplate>
    );
}
