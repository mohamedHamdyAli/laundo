<?php

namespace App\Modules\Service\Services;

use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Repositories\ServiceRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class serviceCrudService
{
    protected $serviceRepository;

    protected $responseService;

    public function __construct(ServiceRepository $serviceRepository, ResponseService $responseService)
    {
        $this->serviceRepository = $serviceRepository;
        $this->responseService = $responseService;
    }

    public function addNew(array $request)
    {
        return DB::transaction(fn () => $this->serviceRepository->create($this->payload($request)));
    }

    public function updateRecord(array $request)
    {
        return DB::transaction(function () use ($request) {
            $service = $this->serviceRepository->findById($request['id']);
            $data = $this->payload($request, $service->image);

            // Turning a per-item service into a quoted one makes its grid cells
            // meaningless; drop them rather than leave unreachable rows behind.
            if (($data['pricing_mode'] ?? $service->pricing_mode) === 'quote') {
                ItemPrice::where('service_id', $service->id)->delete();
            }

            return $this->serviceRepository->update($request['id'], $data);
        });
    }

    public function deleteRecord($id)
    {
        return DB::transaction(function () use ($id) {
            $service = $this->serviceRepository->findById($id);
            DeleteImage($service->image);

            // item_prices rows cascade at the database level.
            return $this->serviceRepository->delete($id);
        });
    }

    public function shredData($id = null)
    {
        $data = ['services' => $this->serviceRepository->getAllPaginated()];

        if ($id) {
            $data['row'] = $this->serviceRepository->findById($id);
        }

        return $data;
    }

    public function search($query, $perPage = 10)
    {
        return $this->serviceRepository->search($query, $perPage);
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->serviceRepository->findById($id), $status);
    }

    /**
     * Shapes the request into a column payload, encoding the translatable fields
     * the same way every other module in the project does.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function payload(array $request, ?string $existingImage = null): array
    {
        $data = array_filter([
            'pricing_mode' => $request['pricing_mode'] ?? null,
            'duration_min' => $request['duration_min'] ?? null,
            'duration_max' => $request['duration_max'] ?? null,
            'duration_unit' => $request['duration_unit'] ?? null,
            'sort_order' => $request['sort_order'] ?? null,
            'status' => $request['status'] ?? null,
        ], fn ($value) => ! is_null($value));

        if (isset($request['name'])) {
            $data['name'] = json_encode($request['name'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($request['description'])) {
            $data['description'] = json_encode($request['description'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($request['image'])) {
            $data['image'] = uploadOrUpdateImage($request['image'], 'images/services/image', $existingImage);
        }

        return $data;
    }
}
