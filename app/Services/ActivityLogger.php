<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Modules\Order\Models\Order;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Who changed what — one row per model created, updated or deleted.
 *
 * Fed by the Eloquent events (see ActivityLogServiceProvider), so every screen
 * and every endpoint is recorded without its own code: a screen added next
 * month is covered on the day it ships. What it cannot see is a bulk write that
 * never loads a model — `Model::where(...)->update()` or `DB::table()` — which
 * fires no event; the main panel actions all go through models.
 *
 * **A failure here never fails the change.** The row is written inside the
 * same transaction as the change it describes — so a change rolled back leaves
 * no row behind — but an error writing it is logged and swallowed: a price the
 * operator saved must not be refused because the history of it could not be.
 */
class ActivityLogger
{
    private const MAX_VALUE = 500;

    private static bool $ready = false;

    public function record(Model $model, string $event): void
    {
        if (! config('activity.enabled', true) || $this->excluded($model) || ! $this->ready()) {
            return;
        }

        try {
            $diff = $this->diff($model, $event);

            // An update that only moved a timestamp or a driver's position.
            if ($event === 'updated' && $diff === []) {
                return;
            }

            $this->write([
                'event' => $event,
                'subject_type' => $model->getMorphClass(),
                'subject_id' => $model->getKey(),
                'subject_label' => $this->label($model),
                'order_id' => $this->orderId($model),
                'diff' => $diff ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[activity] not recorded', [
                'model' => $model::class, 'event' => $event, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A change that is not a model's — a file an editor writes, such as a
     * language's validation messages. Recorded against the record it belongs
     * to, so it reads in that record's history like any other edit; the
     * events above never see it, because no model was saved.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $diff
     */
    public function recordChanges(Model $subject, array $diff): void
    {
        if ($diff === [] || ! config('activity.enabled', true) || $this->excluded($subject) || ! $this->ready()) {
            return;
        }

        try {
            $this->write([
                'event' => 'updated',
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'subject_label' => $this->label($subject),
                'order_id' => null,
                'diff' => array_map(
                    fn (array $change) => ['old' => $this->value($change['old']), 'new' => $this->value($change['new'])],
                    $diff,
                ),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[activity] not recorded', [
                'model' => $subject::class, 'event' => 'updated', 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A panel sign-in or sign-out — not a change to anything, but the first
     * thing somebody asks when a change looks wrong is who was in the panel.
     */
    public function auth(?Authenticatable $user, string $event): void
    {
        if (! config('activity.enabled', true) || ! $this->ready() || ! $user instanceof Model) {
            return;
        }

        try {
            $this->write([
                'event' => $event,
                'subject_type' => $user->getMorphClass(),
                'subject_id' => $user->getKey(),
                'subject_label' => $this->label($user),
                // `/login` and `/laundry/login` sit outside `/admin`, but they
                // are the panel's own doors — not the public site.
                'source' => $this->source() === ActivityLog::API ? ActivityLog::API : ActivityLog::DASHBOARD,
            ], $user);
        } catch (\Throwable $e) {
            Log::warning('[activity] sign-in not recorded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(array $row, ?Authenticatable $actor = null): void
    {
        $request = app()->bound('request') ? request() : null;
        $actor ??= auth()->user();

        ActivityLog::create($row + [
            'user_id' => $actor?->getAuthIdentifier(),
            // Copied: an account deleted later must not blank «who did this».
            'actor_name' => $actor instanceof User ? $actor->name : null,
            'actor_role' => $actor instanceof User ? $actor->role?->slug : null,
            'source' => $this->source(),
            'route' => $request?->route()?->getName() ?? ($request?->route() ? $request->method().' '.$request->path() : null),
            'ip' => $request?->route() ? $request->ip() : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Where the change came from: the panel, an app, the public site, or
     * nobody — a scheduled command, a queue job, a migration.
     */
    private function source(): string
    {
        $request = app()->bound('request') ? request() : null;
        $route = $request?->route();

        if (! $route) {
            return ActivityLog::SYSTEM;
        }

        $path = $request->path();

        if (str_starts_with($path, 'api/')) {
            return ActivityLog::API;
        }

        if (str_starts_with($path, 'admin') || str_starts_with((string) $route->getName(), 'admin.')) {
            return ActivityLog::DASHBOARD;
        }

        return ActivityLog::SITE;
    }

    /**
     * Each field before and after.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(Model $model, string $event): array
    {
        $ignored = array_flip(config('activity.ignored_attributes', []));
        $attributes = $model->getAttributes();

        $keys = match ($event) {
            'updated' => array_keys($model->getChanges()),
            default => array_keys($attributes),
        };

        $diff = [];

        foreach ($keys as $key) {
            if (isset($ignored[$key])) {
                continue;
            }

            $old = $event === 'created' ? null : $model->getRawOriginal($key);
            $new = $event === 'deleted' ? null : ($attributes[$key] ?? null);

            // A new record's empty fields say nothing.
            if ($event === 'created' && ($new === null || $new === '')) {
                continue;
            }

            if ($this->secret($model, $key)) {
                $diff[$key] = ['old' => $old === null ? null : '••••', 'new' => $new === null ? null : '••••'];

                continue;
            }

            $diff[$key] = ['old' => $this->value($old), 'new' => $this->value($new)];
        }

        return $diff;
    }

    private function secret(Model $model, string $key): bool
    {
        $lower = strtolower($key);

        foreach (config('activity.redacted', []) as $needle) {
            if (str_contains($lower, strtolower($needle))) {
                return true;
            }
        }

        // A setting keeps its value in one column; its key says whether that
        // value is a credential.
        if ($model instanceof Setting && $key === 'value') {
            return in_array($model->getAttribute('key'), config('activity.redacted_setting_keys', []), true);
        }

        return false;
    }

    private function value(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE) {
            return mb_substr($value, 0, self::MAX_VALUE).'…';
        }

        return $value;
    }

    /**
     * What a person would call this record: an order's code, a name in the
     * panel's language, a title, a setting's key — copied, so a deleted record
     * is still recognisable in its history.
     */
    private function label(Model $model): ?string
    {
        if ($model instanceof Order) {
            return '#'.$model->getAttribute('code');
        }

        foreach (['name', 'title', 'question', 'key', 'code', 'phone', 'email'] as $field) {
            $raw = $model->getAttributes()[$field] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $decoded = is_string($raw) && str_starts_with(ltrim($raw), '{') ? json_decode($raw, true) : null;

            if (is_array($decoded)) {
                // Every language, so each reader sees the name in their own —
                // not in whichever language the person who changed it had on.
                $languages = array_filter($decoded, fn ($v) => is_string($v) && filled($v));
                $json = json_encode($languages, JSON_UNESCAPED_UNICODE);

                if ($languages !== [] && $json !== false && mb_strlen($json) <= 255) {
                    return $json;
                }

                $picked = $languages[app()->getLocale()] ?? reset($languages);

                return $picked ? Str::limit((string) $picked, 190) : null;
            }

            return Str::limit((string) $raw, 190);
        }

        return null;
    }

    private function orderId(Model $model): ?int
    {
        if ($model instanceof Order) {
            return (int) $model->getKey();
        }

        $orderId = $model->getAttributes()['order_id'] ?? $model->getRawOriginal('order_id');

        return $orderId ? (int) $orderId : null;
    }

    private function excluded(Model $model): bool
    {
        foreach (config('activity.excluded', []) as $class) {
            if ($model instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The table exists. Cached only once it does: a migration run fires model
     * events before this table is created, and caching «no» there would leave
     * the rest of that process — a test run, say — recording nothing.
     */
    private function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        return self::$ready = Schema::hasTable('activity_logs');
    }
}
