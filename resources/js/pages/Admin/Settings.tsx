import { Head, router, useForm } from '@inertiajs/react';
import { Plus, RotateCcw, X } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index, update } from '@/routes/admin/settings';

/**
 * One admin-tunable row as the server states it. The list is generated from the
 * `SettingKey` registry, so this shape is the registry's, not the page's — see
 * Admin\SettingsController::settingRows().
 */
type SettingRow = {
    key: string;
    type: 'text' | 'integer' | 'boolean' | 'json';
    group: string;
    label: string;
    value: number | string | boolean | PackageRow[];
    default: number | string | boolean | PackageRow[];
    overridden: boolean;
};

type PackageRow = {
    stars: number;
    coins: number;
};

const GROUPS = ['economy', 'baseline', 'access', 'reminders'] as const;

export default function Settings({ settings }: { settings: SettingRow[] }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('admin.settings.title')} />

            <h1 className="sr-only">{t('admin.settings.title')}</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title={t('admin.settings.title')}
                    description={t('admin.settings.description')}
                />

                {GROUPS.map((group) => (
                    <section key={group} className="space-y-3">
                        <h2 className="text-lg font-medium tracking-tight">
                            {t(`admin.settings.groups.${group}`)}
                        </h2>

                        <div className="space-y-3">
                            {settings
                                .filter((setting) => setting.group === group)
                                .map((setting) => (
                                    <SettingCard
                                        key={setting.key}
                                        setting={setting}
                                    />
                                ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

function SettingCard({ setting }: { setting: SettingRow }) {
    const { t } = useTranslation();
    const form = useForm<{ value: number | string | boolean | PackageRow[] }>({
        value: setting.value,
    });

    const submit = () => {
        form.put(update(setting.key).url, {
            preserveScroll: true,
        });
    };

    const reset = () => {
        form.clearErrors();
        router.delete(destroy(setting.key).url, {
            preserveScroll: true,
        });
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-4">
                    <div className="space-y-1">
                        <CardTitle className="text-base">
                            {setting.label}
                        </CardTitle>

                        {setting.overridden && (
                            <p className="text-xs text-muted-foreground">
                                {t('admin.settings.default')}:{' '}
                                {describeDefault(setting)} ·{' '}
                                {t('admin.settings.overridden')}
                            </p>
                        )}
                    </div>

                    {setting.overridden && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={reset}
                            title={t('admin.settings.reset')}
                        >
                            <RotateCcw />
                            <span className="hidden sm:inline">
                                {t('admin.settings.reset')}
                            </span>
                        </Button>
                    )}
                </div>
            </CardHeader>

            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    <ValueEditor
                        setting={setting}
                        value={form.data.value}
                        onChange={(value) => form.setData('value', value)}
                        disabled={form.processing}
                    />

                    <InputError message={form.errors.value} />

                    <Button type="submit" disabled={form.processing} size="sm">
                        {t('admin.settings.save')}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function ValueEditor({
    setting,
    value,
    onChange,
    disabled,
}: {
    setting: SettingRow;
    value: number | string | boolean | PackageRow[];
    onChange: (value: number | string | boolean | PackageRow[]) => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();

    if (setting.type === 'json') {
        return (
            <PackagesEditor
                packages={value as PackageRow[]}
                onChange={onChange}
                disabled={disabled}
            />
        );
    }

    if (setting.type === 'boolean') {
        return (
            <Label className="flex items-center gap-3 font-normal">
                <Checkbox
                    checked={value === true}
                    onCheckedChange={(checked) => onChange(checked === true)}
                    disabled={disabled}
                />
                {t('admin.settings.value')}
            </Label>
        );
    }

    return (
        <div className="grid max-w-sm gap-2">
            <Label htmlFor={`setting-${setting.key}`}>
                {t('admin.settings.value')}
            </Label>

            <Input
                id={`setting-${setting.key}`}
                type={setting.type === 'integer' ? 'number' : 'text'}
                inputMode={setting.type === 'integer' ? 'numeric' : undefined}
                min={setting.type === 'integer' ? 0 : undefined}
                value={value as string | number}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                dir={setting.type === 'integer' ? 'ltr' : undefined}
            />
        </div>
    );
}

function PackagesEditor({
    packages,
    onChange,
    disabled,
}: {
    packages: PackageRow[];
    onChange: (value: PackageRow[]) => void;
    disabled: boolean;
}) {
    const { t } = useTranslation();

    const updateRow = (row: number, field: 'stars' | 'coins', raw: string) => {
        onChange(
            packages.map((pkg, index) =>
                index === row ? { ...pkg, [field]: raw } : pkg,
            ),
        );
    };

    const removeRow = (row: number) => {
        onChange(packages.filter((_, index) => index !== row));
    };

    const addRow = () => {
        onChange([...packages, { stars: 0, coins: 0 }]);
    };

    return (
        <div className="space-y-3">
            <div className="overflow-hidden rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-start">
                        <tr>
                            <th className="px-3 py-2 text-start font-medium">
                                {t('admin.settings.stars')}
                            </th>
                            <th className="px-3 py-2 text-start font-medium">
                                {t('admin.settings.coins')}
                            </th>
                            <th className="w-12" />
                        </tr>
                    </thead>

                    <tbody>
                        {packages.map((pkg, row) => (
                            <tr key={row} className="border-t">
                                <td className="px-3 py-1.5">
                                    <Input
                                        type="number"
                                        min={1}
                                        className="h-8 w-28"
                                        value={pkg.stars}
                                        onChange={(event) =>
                                            updateRow(
                                                row,
                                                'stars',
                                                event.target.value,
                                            )
                                        }
                                        disabled={disabled}
                                        dir="ltr"
                                    />
                                </td>

                                <td className="px-3 py-1.5">
                                    <Input
                                        type="number"
                                        min={0}
                                        className="h-8 w-28"
                                        value={pkg.coins}
                                        onChange={(event) =>
                                            updateRow(
                                                row,
                                                'coins',
                                                event.target.value,
                                            )
                                        }
                                        disabled={disabled}
                                        dir="ltr"
                                    />
                                </td>

                                <td className="px-3 py-1.5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        onClick={() => removeRow(row)}
                                        disabled={disabled}
                                        title={t(
                                            'admin.settings.remove_package',
                                        )}
                                    >
                                        <X />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={addRow}
                disabled={disabled}
            >
                <Plus />
                {t('admin.settings.add_package')}
            </Button>
        </div>
    );
}

function describeDefault(setting: SettingRow): string {
    if (setting.type === 'json') {
        return (setting.default as PackageRow[])
            .map((pkg) => `${pkg.stars} → ${pkg.coins}`)
            .join(', ');
    }

    if (setting.type === 'boolean') {
        return String(setting.default);
    }

    return String(setting.default);
}

Settings.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: index.url(),
        },
        {
            title: 'Settings',
            href: index.url(),
        },
    ],
};
