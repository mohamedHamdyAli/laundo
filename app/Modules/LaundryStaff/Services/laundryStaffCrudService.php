<?php

namespace App\Modules\LaundryStaff\Services;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryStaff\Repositories\LaundryStaffRepository;
use App\Services\ResponseService;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;

class laundryStaffCrudService
{
    protected $laundryStaffRepository;

    protected $responseService;

    public function __construct(LaundryStaffRepository $laundryStaffRepository, ResponseService $responseService)
    {
        $this->laundryStaffRepository = $laundryStaffRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(function () use ($request) {
            $request['image_profile'] = uploadOrUpdateImage(
                $request['image_profile'] ?? null,
                'images/laundry-staff/image'
            );

            // BelongsToLaundry overwrites this on create for a laundry actor, so
            // the value below only matters for a super admin.
            $request['laundry_id'] = LaundryContext::currentId() ?? ($request['laundry_id'] ?? null);

            return $this->laundryStaffRepository->create($request);
        });
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $data = array_filter($request, fn ($value) => ! is_null($value));

            // Re-parenting an account to a different laundry is a super admin
            // action only; a tenant's own laundry is never up for negotiation.
            if (LaundryContext::isTenant()) {
                unset($data['laundry_id']);
            }

            if (isset($data['image_profile'])) {
                $existing = $this->laundryStaffRepository->findById($data['id']);
                $data['image_profile'] = uploadOrUpdateImage(
                    $data['image_profile'],
                    'images/laundry-staff/image',
                    $existing->image_profile
                );
            }

            return $this->laundryStaffRepository->update($data['id'], $data);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(fn () => $this->laundryStaffRepository->delete($id));
    }

    public function shredData($id = null)
    {
        $data = [
            'staff' => $this->laundryStaffRepository->getAllPaginated(),
            'roles' => Role::where('type', 'laundry')->get(),
            // Scoped by the Laundry model's own global scope: a laundry owner
            // gets a single-option list, a super admin gets every laundry.
            'laundries' => Laundry::where('status', 'active')->get(),
        ];

        if ($id) {
            $data['row'] = $this->laundryStaffRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 10)
    {
        return $this->laundryStaffRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        $staff = $this->laundryStaffRepository->findById($id);

        return $this->responseService->toggleStatus($staff, $status);
    }
}
