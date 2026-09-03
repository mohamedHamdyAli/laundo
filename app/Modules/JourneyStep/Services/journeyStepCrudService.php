<?php

namespace App\Modules\JourneyStep\Services;

use App\Modules\JourneyStep\Repositories\JourneyStepRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for «رحلتك معنا بسيطة».
 */
class journeyStepCrudService
{
    protected $journeyStepRepository;

    protected $responseService;

    public function __construct(JourneyStepRepository $journeyStepRepository, ResponseService $responseService)
    {
        $this->journeyStepRepository = $journeyStepRepository;
        $this->responseService = $responseService;
    }

    public function getAllJourneySteps()
    {
        return $this->journeyStepRepository->getAll();
    }

    public function searchJourneySteps($query)
    {
        return $this->journeyStepRepository->search($query);
    }

    /**
     * The list under its plural key, and — when an id is given — the single
     * record under `row`.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null)
    {
        $data = [];

        if ($id) {
            $data['row'] = $this->journeyStepRepository->find($id);
        }

        return $data;
    }

    public function addJourneyStep(array $data)
    {
        return DB::transaction(function () use ($data) {
            $data['image'] = uploadOrUpdateImage($data['image'] ?? null, 'images/journey-steps/image');
            $data['title'] = json_encode($data['title'], JSON_UNESCAPED_UNICODE);
            $data['description'] = json_encode($data['description'], JSON_UNESCAPED_UNICODE);

            return $this->journeyStepRepository->create($data);
        });
    }

    public function updateJourneyStep(array $data)
    {
        // Nulls dropped so an untouched optional field does not overwrite what
        // is stored with nothing.
        $filtered = array_filter($data, fn ($v) => ! is_null($v));

        return DB::transaction(function () use ($filtered) {
            $existing = $this->journeyStepRepository->find($filtered['id']);

            if (isset($filtered['image'])) {
                $filtered['image'] = uploadOrUpdateImage(
                    $filtered['image'],
                    'images/journey-steps/image',
                    $existing->image
                );
            }

            $filtered['title'] = json_encode($filtered['title'], JSON_UNESCAPED_UNICODE);
            $filtered['description'] = json_encode($filtered['description'], JSON_UNESCAPED_UNICODE);

            return $this->journeyStepRepository->update($filtered['id'], $filtered);
        });
    }

    public function deleteJourneyStep($id)
    {
        return DB::transaction(fn () => $this->journeyStepRepository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        $step = $this->journeyStepRepository->find($id);

        return $this->responseService->toggleStatus($step, $status);
    }
}
