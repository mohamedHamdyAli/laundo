<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * An activity row as the owner reads it — a sentence, not a record.
 *
 * «تعديل مدينة «القاهرة»», who did it by their role in words («مدير المنصة»),
 * where from in words («لوحة التحكم — المدن»), and only the fields that mean
 * something to a person, each value the way the screens show it: a status by
 * its label, an id by the name of what it points at, a date in the display
 * timezone, a password as «changed» and nothing more.
 *
 * The activity log screen, its Excel export and the order's history all word a
 * change through here, so the same change never reads two ways. What is
 * *recorded* is ActivityLogger's business; this only decides how it reads.
 *
 * @phpstan-type Field array{key: string, label: string, old: string, new: string}
 * @phpstan-type Row array{log: ActivityLog, at: Carbon|null, event: string, title: string, actor: string, role: string|null, where: string, fields: list<Field>}
 */
class ActivityPresenter
{
    private const SECRET = '••••';

    /**
     * Names already looked up, by model class and id — kept across calls so an
     * export presenting rows one at a time asks for each name once.
     *
     * @var array<string, array<int|string, string>>
     */
    private array $names = [];

    /** @var array<string, array<string, string>> */
    private array $casts = [];

    /** @var array<string, string>|null route prefix => menu key */
    private ?array $screens = null;

    /**
     * @param  iterable<ActivityLog>  $logs
     * @return list<Row>
     */
    public function present(iterable $logs): array
    {
        $logs = collect($logs);
        $this->resolveNames($logs);

        return $logs->map(fn (ActivityLog $log) => $this->row($log))->values()->all();
    }

    /**
     * @return Row
     */
    private function row(ActivityLog $log): array
    {
        return [
            'log' => $log,
            'at' => $log->created_at,
            'event' => $log->event,
            'title' => $this->title($log),
            'actor' => $log->actor_name ?: __('The system'),
            'role' => $this->role($log->actor_role),
            'where' => $this->where($log),
            'fields' => $this->fields($log),
        ];
    }

    /**
     * «تعديل مدينة «القاهرة»» — what happened, to what, in one line.
     */
    private function title(ActivityLog $log): string
    {
        if ($log->event === 'login') {
            return __('Signed in to the control panel');
        }

        if ($log->event === 'logout') {
            return __('Signed out of the control panel');
        }

        $verb = match ($log->event) {
            'created' => __('Added'),
            'deleted' => __('Removed'),
            default => __('Changed'),
        };

        $title = $verb.' '.$log->subjectKind();
        $name = $log->subjectName();

        return $name ? $title.' «'.$name.'»' : $title;
    }

    private function role(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        return __(config("activity.roles.$slug") ?? Str::headline($slug));
    }

    /**
     * «لوحة التحكم — المدن», «أبلكيشن المندوب», «تلقائي من النظام».
     */
    private function where(ActivityLog $log): string
    {
        return match ($log->source) {
            ActivityLog::DASHBOARD => ($screen = $this->screen($log->route))
                ? __('Control panel').' — '.$screen
                : __('Control panel'),
            ActivityLog::API => $log->actor_role === 'driver' ? __('Driver app') : __('Customer app'),
            ActivityLog::SITE => __('Website'),
            default => __('Automatically, by the system'),
        };
    }

    /**
     * The sidebar screen a route belongs to: `admin.city.update` → «المدن»,
     * `admin.earning.index` → «أرباح المناديب». Matched on the menu's own
     * routes, so a screen added to the sidebar is named here the day it ships.
     */
    private function screen(?string $route): ?string
    {
        if (! $route || ! str_starts_with($route, 'admin.')) {
            return null;
        }

        if (str_starts_with($route, 'admin.spreadsheet.')) {
            return __('Excel import');
        }

        if ($this->screens === null) {
            $this->screens = [];

            foreach ((array) config('menu.routes', []) as $key => $name) {
                if (is_string($name) && str_contains($name, '.')) {
                    $this->screens[Str::beforeLast($name, '.')] = (string) $key;
                }
            }
        }

        $parts = explode('.', $route);

        while (count($parts) > 2) {
            array_pop($parts);
            $key = $this->screens[implode('.', $parts)] ?? null;

            if ($key !== null) {
                $title = config("menu.titles.$key");

                return is_string($title) ? __($title) : null;
            }
        }

        return null;
    }

    /**
     * Each field worth showing, before and after, in words.
     *
     * @return list<Field>
     */
    private function fields(ActivityLog $log): array
    {
        $fields = [];

        foreach ((array) $log->diff as $key => $values) {
            $key = (string) $key;

            if ($this->hidden($key)) {
                continue;
            }

            $old = $values['old'] ?? null;
            $new = $values['new'] ?? null;

            // A password, a key: that it changed is the whole story.
            if ($old === self::SECRET || $new === self::SECRET) {
                $fields[] = ['key' => $key, 'label' => ActivityLog::fieldLabel($key), 'old' => '', 'new' => __('Changed — hidden for security')];

                continue;
            }

            $fields[] = [
                'key' => $key,
                'label' => ActivityLog::fieldLabel($key),
                'old' => $this->value($log->subject_type, $key, $old),
                'new' => $this->value($log->subject_type, $key, $new),
            ];
        }

        return $fields;
    }

    private function hidden(string $key): bool
    {
        if (in_array($key, (array) config('activity.hidden_fields', []), true)) {
            return true;
        }

        foreach ((array) config('activity.hidden_suffixes', []) as $suffix) {
            if (str_ends_with($key, (string) $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stored value as the screens show it.
     */
    private function value(?string $type, string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $reference = config("activity.references.$key");

        if (is_string($reference) && is_scalar($value)) {
            return $this->names[$reference][$value] ?? '#'.$value;
        }

        $cast = $this->castsFor($type)[$key] ?? null;

        if ($cast && is_subclass_of($cast, \BackedEnum::class) && is_scalar($value)) {
            try {
                $case = $cast::tryFrom($value);
            } catch (\Throwable) {
                $case = null;
            }

            if ($case && method_exists($case, 'label')) {
                return (string) __($case->label());
            }
        }

        if (in_array($cast, ['bool', 'boolean'], true) || in_array($value, ['true', 'false'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? __('Yes') : __('No');
        }

        if ($key === 'status' && in_array($value, ['active', 'inactive'], true)) {
            return $value === 'active' ? __('Active') : __('Inactive');
        }

        if ($key === 'phase' && class_basename((string) $type) === 'OrderItem') {
            return $value === 'final' ? __('Reviewed') : __('Customer estimate');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return humanDate($value, 'Y-m-d H:i');
        }

        if (is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return (string) ($decoded[app()->getLocale()] ?? collect($decoded)->first(fn ($v) => filled($v)) ?? '—');
            }
        }

        if (is_array($value)) {
            return collect($value)->flatten()->filter(fn ($v) => is_scalar($v))->implode('، ');
        }

        return is_scalar($value) ? (string) $value : '—';
    }

    /**
     * @return array<string, string>
     */
    private function castsFor(?string $type): array
    {
        if (! $type || ! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return [];
        }

        return $this->casts[$type] ??= array_filter((new $type)->getCasts(), 'is_string');
    }

    /**
     * One query per kind of record named anywhere in these rows, for the ids
     * not already known. Scopes stay on: a laundry owner reading a reassigned
     * order sees the other laundry's number, not its name.
     *
     * @param  Collection<int, ActivityLog>  $logs
     */
    private function resolveNames(Collection $logs): void
    {
        $references = (array) config('activity.references', []);
        $wanted = [];

        foreach ($logs as $log) {
            foreach ((array) $log->diff as $key => $values) {
                $class = $references[$key] ?? null;

                if (! is_string($class)) {
                    continue;
                }

                foreach (['old', 'new'] as $side) {
                    $id = $values[$side] ?? null;

                    if (is_scalar($id) && $id !== '' && ! isset($this->names[$class][$id])) {
                        $wanted[$class][] = $id;
                    }
                }
            }
        }

        foreach ($wanted as $class => $ids) {
            $class::query()
                ->whereKey(array_values(array_unique($ids)))
                ->get()
                ->each(function (Model $model) use ($class) {
                    $this->names[$class][$model->getKey()] = $this->nameOf($model);
                });
        }
    }

    private function nameOf(Model $model): string
    {
        if ($model instanceof Role) {
            return $this->role((string) $model->getAttribute('slug')) ?? (string) $model->getAttribute('name');
        }

        foreach (['name', 'title'] as $field) {
            $value = $model->getAttribute($field);

            if (is_object($value)) {
                $value = pickTranslation($value, app()->getLocale(), '');
            }

            if (is_string($value) && filled($value)) {
                return $value;
            }
        }

        // A time slot names itself («09:00 – 12:00»).
        if (method_exists($model, 'label')) {
            try {
                $label = $model->label();

                if (is_string($label) && filled($label)) {
                    return $label;
                }
            } catch (\Throwable) {
                // Fall through to a plain column.
            }
        }

        foreach (['label', 'code', 'question', 'key', 'phone', 'email'] as $field) {
            $value = $model->getAttribute($field);

            if (is_string($value) && filled($value)) {
                return $value;
            }
        }

        return '#'.$model->getKey();
    }
}
