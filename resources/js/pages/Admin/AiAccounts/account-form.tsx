import { router, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { index, store, update } from '@/routes/admin/ai-accounts';

/**
 * One provider account as the panel states it — `toRow()`'s shape. The
 * `config` here is already masked: a stored secret arrives as the `__set__`
 * sentinel, never as the credential itself.
 */
export type AccountRow = {
    id: number;
    name: string;
    capability: { id: number; key: string; label: string };
    driver: string | null;
    driver_label: string;
    model: string | null;
    is_active: boolean;
    sort_order: number;
    config: Record<string, string>;
    is_configured: boolean;
    is_cooling_down: boolean;
    unavailable_until: string | null;
    last_failure_reason: string | null;
    last_succeeded_at: string | null;
    input_token_limit: number | null;
    output_token_limit: number | null;
    total_token_limit: number | null;
    limit_period: 'daily' | 'monthly';
    limit_timezone: string;
    input_token_price_per_million: number | null;
    output_token_price_per_million: number | null;
};

export type CapabilityOption = { id: number; key: string; label: string };

export type DriverField = {
    key: string;
    label: string;
    required: boolean;
    secret?: boolean;
    placeholder?: string;
    helper?: string;
};

export type DriverOption = {
    key: string;
    label: string;
    fields: DriverField[];
};

type ConfigForm = Record<string, string>;

type AccountForm = {
    name: string;
    ai_capability_id: string;
    driver: string;
    model: string;
    is_active: boolean;
    sort_order: string;
    config: ConfigForm;
    input_token_limit: string;
    output_token_limit: string;
    total_token_limit: string;
    limit_period: string;
    limit_timezone: string;
    input_token_price_per_million: string;
    output_token_price_per_million: string;
};

/**
 * Create and edit share one form: the only difference is whether an existing
 * account's values seed it and which route it submits to. Secret config
 * fields always start empty — the placeholder says a credential is stored,
 * and an empty submit means "keep it" server-side.
 */
export function AccountForm({
    account,
    capabilities,
    drivers,
}: {
    account: AccountRow | null;
    capabilities: CapabilityOption[];
    drivers: DriverOption[];
}) {
    const { t } = useTranslation();

    const form = useForm<AccountForm>({
        name: account?.name ?? '',
        ai_capability_id: account ? String(account.capability.id) : '',
        driver: account?.driver ?? drivers[0]?.key ?? '',
        model: account?.model ?? '',
        is_active: account?.is_active ?? true,
        sort_order: String(account?.sort_order ?? 0),
        // Secrets never seed the form: only their presence does. Non-secret
        // fields (a base URL) travel through maskedConfig unmasked.
        config: (() => {
            const config: ConfigForm = {};

            for (const [key, value] of Object.entries(account?.config ?? {})) {
                config[key] = value === '__set__' ? '' : value;
            }

            return config;
        })(),
        input_token_limit: account?.input_token_limit?.toString() ?? '',
        output_token_limit: account?.output_token_limit?.toString() ?? '',
        total_token_limit: account?.total_token_limit?.toString() ?? '',
        limit_period: account?.limit_period ?? 'monthly',
        limit_timezone: account?.limit_timezone ?? 'UTC',
        input_token_price_per_million:
            account?.input_token_price_per_million?.toString() ?? '',
        output_token_price_per_million:
            account?.output_token_price_per_million?.toString() ?? '',
    });

    const driver = drivers.find((option) => option.key === form.data.driver);

    const submit = () => {
        const options = { preserveScroll: true };

        if (account) {
            form.put(update(account.id).url, options);

            return;
        }

        form.post(store.url(), options);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        {t('admin.ai_accounts.connection')}
                    </CardTitle>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">
                            {t('admin.ai_accounts.name')}
                        </Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            disabled={form.processing}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="capability">
                            {t('admin.ai_accounts.capability')}
                        </Label>
                        <Select
                            value={form.data.ai_capability_id}
                            onValueChange={(value) =>
                                form.setData('ai_capability_id', value)
                            }
                        >
                            <SelectTrigger id="capability" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {capabilities.map((capability) => (
                                    <SelectItem
                                        key={capability.id}
                                        value={String(capability.id)}
                                    >
                                        {capability.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.ai_capability_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="driver">
                            {t('admin.ai_accounts.driver')}
                        </Label>
                        <Select
                            value={form.data.driver}
                            onValueChange={(value) =>
                                form.setData('driver', value)
                            }
                        >
                            <SelectTrigger id="driver" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {drivers.map((option) => (
                                    <SelectItem
                                        key={option.key}
                                        value={option.key}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.driver} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="model">
                            {t('admin.ai_accounts.model')}
                        </Label>
                        <Input
                            id="model"
                            value={form.data.model}
                            onChange={(event) =>
                                form.setData('model', event.target.value)
                            }
                            disabled={form.processing}
                        />
                        <InputError message={form.errors.model} />
                    </div>

                    {driver?.fields.map((field) => {
                        const isSecret = field.secret === true;
                        const hasStored =
                            account?.config[field.key] === '__set__';

                        return (
                            <div key={field.key} className="grid gap-2">
                                <Label htmlFor={`config-${field.key}`}>
                                    {t(`admin.ai_accounts.fields.${field.key}`)}
                                </Label>

                                <Input
                                    id={`config-${field.key}`}
                                    type={isSecret ? 'password' : 'text'}
                                    autoComplete="off"
                                    placeholder={
                                        isSecret && hasStored
                                            ? '••••••••'
                                            : (field.placeholder ?? '')
                                    }
                                    value={form.data.config[field.key] ?? ''}
                                    onChange={(event) =>
                                        form.setData('config', {
                                            ...form.data.config,
                                            [field.key]: event.target.value,
                                        })
                                    }
                                    disabled={form.processing}
                                    dir={
                                        field.key === 'url' ? 'ltr' : undefined
                                    }
                                />

                                {isSecret && hasStored && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('admin.ai_accounts.set_hint')}
                                    </p>
                                )}

                                <InputError
                                    message={form.errors[`config.${field.key}`]}
                                />
                            </div>
                        );
                    })}

                    <Label className="flex items-center gap-3 font-normal">
                        <Checkbox
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked === true)
                            }
                            disabled={form.processing}
                        />
                        {t('admin.ai_accounts.active')}
                    </Label>
                    <InputError message={form.errors.is_active} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        {t('admin.ai_accounts.limits')}
                    </CardTitle>
                </CardHeader>

                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        {(
                            [
                                ['input_token_limit', 'input_token_limit'],
                                ['output_token_limit', 'output_token_limit'],
                                ['total_token_limit', 'total_token_limit'],
                            ] as const
                        ).map(([key, labelKey]) => (
                            <div key={key} className="grid gap-2">
                                <Label htmlFor={key}>
                                    {t(`admin.ai_accounts.${labelKey}`)}
                                </Label>
                                <Input
                                    id={key}
                                    type="number"
                                    min={0}
                                    value={form.data[key]}
                                    onChange={(event) =>
                                        form.setData(key, event.target.value)
                                    }
                                    disabled={form.processing}
                                    dir="ltr"
                                />
                                <InputError message={form.errors[key]} />
                            </div>
                        ))}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="limit_period">
                                {t('admin.ai_accounts.limit_period')}
                            </Label>
                            <Select
                                value={form.data.limit_period}
                                onValueChange={(value) =>
                                    form.setData('limit_period', value)
                                }
                            >
                                <SelectTrigger
                                    id="limit_period"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(['daily', 'monthly'] as const).map(
                                        (period) => (
                                            <SelectItem
                                                key={period}
                                                value={period}
                                            >
                                                {t(
                                                    `admin.ai_accounts.periods.${period}`,
                                                )}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.limit_period} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="limit_timezone">
                                {t('admin.ai_accounts.limit_timezone')}
                            </Label>
                            <Input
                                id="limit_timezone"
                                value={form.data.limit_timezone}
                                onChange={(event) =>
                                    form.setData(
                                        'limit_timezone',
                                        event.target.value,
                                    )
                                }
                                disabled={form.processing}
                                dir="ltr"
                            />
                            <InputError message={form.errors.limit_timezone} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        {t('admin.ai_accounts.prices')}
                    </CardTitle>
                </CardHeader>

                <CardContent>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(
                            [
                                'input_token_price_per_million',
                                'output_token_price_per_million',
                            ] as const
                        ).map((key) => (
                            <div key={key} className="grid gap-2">
                                <Label htmlFor={key}>
                                    {t(`admin.ai_accounts.${key}`)}
                                </Label>
                                <Input
                                    id={key}
                                    type="number"
                                    min={0}
                                    value={form.data[key]}
                                    onChange={(event) =>
                                        form.setData(key, event.target.value)
                                    }
                                    disabled={form.processing}
                                    dir="ltr"
                                />
                                <InputError message={form.errors[key]} />
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {t('admin.ai_accounts.save')}
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    disabled={form.processing}
                    onClick={() => router.visit(index.url())}
                >
                    {t('admin.ai_accounts.cancel')}
                </Button>
            </div>
        </form>
    );
}
