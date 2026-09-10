<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Notification\Services\DriverApplicationNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Driver leads: taking them, and marking them dealt with.
 *
 * There is no create or edit screen. Every row arrives from the public form,
 * and the only thing an operator does to one is pick it up — so `shredData()`
 * returns the list and, given an id, the single row under `row`, and there is
 * no `addNew()` for the panel to call.
 */
class driverApplicationCrudService
{
    /**
     * Take a lead from the public form.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(array $data): DriverApplication
    {
        $application = DriverApplication::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'note' => $data['note'] ?? null,
        ]);

        $this->announce($application);

        return $application;
    }

    /**
     * Somebody rang them.
     *
     * Toggleable rather than one-way: an operator who marks the wrong row can
     * put it back, and a lead that was closed too early belongs in the queue
     * again rather than lost in a list of hundreds.
     */
    public function toggleHandled(DriverApplication $application, ?string $note = null): DriverApplication
    {
        return DB::transaction(function () use ($application, $note) {
            $application->forceFill($application->isWaiting()
                ? [
                    'handled_at' => now(),
                    'handled_by' => Auth::id(),
                    'admin_note' => $note ?: $application->admin_note,
                ]
                : [
                    'handled_at' => null,
                    'handled_by' => null,
                ])->save();

            return $application->refresh();
        });
    }

    public function deleteRecord(int $id): void
    {
        DriverApplication::whereKey($id)->delete();
    }

    /**
     * The universal view-data assembler. The list under `applications` and,
     * when an id is given, the single record under `row`.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null): array
    {
        $data = [
            // Waiting first and oldest first inside that, because the whole
            // screen exists to answer "who has nobody rung yet".
            'applications' => DriverApplication::with('handler:id,name')
                ->orderByRaw('handled_at IS NULL DESC')
                ->orderBy('created_at')
                ->paginate(15),
            'waitingCount' => DriverApplication::waiting()->count(),
        ];

        if ($id) {
            $data['row'] = DriverApplication::with('handler:id,name')->findOrFail($id);
        }

        return $data;
    }

    public function search(?string $query, int $perPage = 15)
    {
        return DriverApplication::with('handler:id,name')
            ->search($query, ['name', 'phone', 'note'])
            ->orderByRaw('handled_at IS NULL DESC')
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /**
     * Tell operations a lead came in.
     *
     * Swallowed: a lead that was taken and not announced is a row somebody
     * finds on the list. A lead that failed to save because a notification did
     * is a person who filled in a form for nothing.
     */
    private function announce(DriverApplication $application): void
    {
        try {
            app(DriverApplicationNotifier::class)->received($application);
        } catch (\Throwable $e) {
            Log::warning('[notifications] driver application', [
                'application' => $application->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
