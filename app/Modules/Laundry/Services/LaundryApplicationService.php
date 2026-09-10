<?php

namespace App\Modules\Laundry\Services;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Notification\Services\LaundryApplicationNotifier;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A laundry applying to join, and somebody deciding.
 *
 * Separate from `laundryCrudService` on purpose. That service is an operator
 * creating a laundry, which is an act of authority: the row is live the moment
 * it is saved. This one is a stranger asking, which is a different thing with
 * different rules — the row is created switched off, and stays off until a
 * person says otherwise.
 *
 * Both halves of an application are created inactive, and both are flipped
 * together on approval. Leaving either behind is the failure mode worth
 * guarding: an approved laundry whose owner is still inactive cannot sign in to
 * the thing that was just approved, and an active owner on a laundry that is
 * still off can sign in to a panel with nothing in it.
 */
class LaundryApplicationService
{
    /**
     * File an application.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): Laundry
    {
        $laundry = DB::transaction(function () use ($data) {
            $laundry = Laundry::withoutGlobalScopes()->create([
                'name' => json_encode($data['name'], JSON_UNESCAPED_UNICODE),
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city_id' => $data['city_id'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'logo' => uploadOrUpdateImage($data['logo'] ?? null, 'images/laundries/logo'),
                // Off, and the two nulls say it is off because nobody has looked
                // yet rather than because somebody switched it off.
                'status' => 'inactive',
                'approved_at' => null,
                'rejected_at' => null,
            ]);

            User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone' => $data['owner_phone'],
                'password' => $data['owner_password'],
                'role_id' => Role::where('slug', 'laundry_owner')->firstOrFail()->id,
                'laundry_id' => $laundry->id,
                'status' => 'inactive',
            ]);

            return $laundry;
        });

        $this->announce($laundry);

        return $laundry;
    }

    /**
     * Let them in.
     */
    public function approve(Laundry $laundry): Laundry
    {
        if ($laundry->isApproved()) {
            throw new RuntimeException('already_approved');
        }

        return DB::transaction(function () use ($laundry) {
            $laundry->forceFill([
                'status' => 'active',
                'approved_at' => now(),
                // Clearing these is the point of allowing a second look: a
                // laundry turned down for a missing address and approved after
                // fixing it must not still read as rejected.
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            // Every account on the laundry, not just the owner: an application
            // approved months after it was filed may already have staff, and
            // reactivating one of two accounts is the half-open door above.
            $laundry->users()->update(['status' => 'active']);

            $this->tell($laundry, approved: true);

            return $laundry->refresh();
        });
    }

    /**
     * Turn them down, with a reason they can act on.
     */
    public function reject(Laundry $laundry, ?string $reason = null): Laundry
    {
        return DB::transaction(function () use ($laundry, $reason) {
            $laundry->forceFill([
                'status' => 'inactive',
                'approved_at' => null,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $laundry->users()->update(['status' => 'inactive']);

            $this->tell($laundry, approved: false);

            return $laundry->refresh();
        });
    }

    /**
     * Tell operations somebody has applied.
     *
     * Swallowed: an application that was filed and not announced is a row
     * somebody finds on the list screen. An application that failed to save
     * because a notification did is a laundry that has to fill the form again.
     */
    private function announce(Laundry $laundry): void
    {
        try {
            app(LaundryApplicationNotifier::class)->received($laundry);
        } catch (\Throwable $e) {
            Log::warning('[notifications] laundry application', [
                'laundry' => $laundry->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the applicant what was decided. Swallowed for the same reason.
     */
    private function tell(Laundry $laundry, bool $approved): void
    {
        try {
            app(LaundryApplicationNotifier::class)->decided($laundry, $approved);
        } catch (\Throwable $e) {
            Log::warning('[notifications] laundry decision', [
                'laundry' => $laundry->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
