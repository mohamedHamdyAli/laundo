<?php

namespace App\Modules\Faq\Services;

use App\Modules\Faq\Repositories\FaqRepository;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;

class faqCrudService
{
    public function __construct(
        private readonly FaqRepository $repository,
        private readonly ResponseService $responseService,
    ) {}

    public function getAllFaqs($perPage = 15)
    {
        return $this->repository->getAll($perPage);
    }

    public function searchFaqs($query, $perPage = 15)
    {
        return $this->repository->search($query, $perPage);
    }

    public function addFaq(array $data)
    {
        return DB::transaction(fn () => $this->repository->create($this->encode($data)));
    }

    public function updateFaq(array $data)
    {
        return DB::transaction(function () use ($data) {
            $id = $data['id'];
            unset($data['id']);

            // Drop the keys the form left empty so an edit that only moves the
            // order does not blank the text.
            $data = array_filter($data, fn ($value) => ! is_null($value));

            return $this->repository->update($id, $this->encode($data));
        });
    }

    public function deleteFaq($id)
    {
        return DB::transaction(fn () => $this->repository->delete($id));
    }

    public function toggleStatus($id, $status)
    {
        return $this->responseService->toggleStatus($this->repository->find($id), $status);
    }

    public function shredData($id = null)
    {
        $data = [
            // The three audiences, so the form does not hardcode them.
            'audiences' => ['both', 'customer', 'driver'],
        ];

        if ($id !== null) {
            $data['row'] = $this->repository->find($id);
        }

        return $data;
    }

    /**
     * Translated columns are encoded here, not cast on the model — the project
     * convention, and the reason JSON_UNESCAPED_UNICODE has to be explicit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function encode(array $data): array
    {
        foreach (['question', 'answer'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }
}
