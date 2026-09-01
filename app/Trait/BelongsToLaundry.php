<?php

namespace App\Trait;

use App\Modules\Laundry\Models\Laundry;
use App\Support\LaundryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confines a model that carries a `laundry_id` column to the current actor's laundry.
 *
 * Two halves, and both are needed — reading is only half of isolation:
 *
 *   1. A global scope filters every query, so a laundry user cannot READ another
 *      tenant's rows, including by guessing an id in a URL.
 *   2. A creating hook overwrites `laundry_id` with the actor's own, so a laundry
 *      user cannot WRITE into another tenant by posting a forged laundry_id.
 *
 * Both are no-ops when LaundryContext::currentId() is null (console, seeders,
 * queue workers, super admins, moderators) — see that class for the rules.
 */
trait BelongsToLaundry
{
    public static function bootBelongsToLaundry(): void
    {
        static::addGlobalScope('laundry', function (Builder $query): void {
            $laundryId = LaundryContext::currentId();

            if ($laundryId === null) {
                return;
            }

            // Table-qualified: without it a join in a later phase makes the
            // column ambiguous and MySQL errors out.
            $query->where($query->getModel()->getTable().'.laundry_id', $laundryId);
        });

        static::creating(function (Model $model): void {
            $laundryId = LaundryContext::currentId();

            if ($laundryId === null) {
                return;
            }

            // Deliberately overwrites rather than defaults: a forged laundry_id in
            // the request payload must not survive.
            $model->setAttribute('laundry_id', $laundryId);
        });
    }

    public function laundry(): BelongsTo
    {
        return $this->belongsTo(Laundry::class, 'laundry_id');
    }
}
