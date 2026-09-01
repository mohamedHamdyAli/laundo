<?php

namespace App\Modules\Faq\Repositories;

use App\Modules\Faq\Models\Faq;
use Illuminate\Database\Eloquent\Builder;

class FaqRepository
{
    public function getAll($perPage = 15)
    {
        return $this->ordered()->paginate($perPage);
    }

    public function search($query, $perPage = 15)
    {
        return Faq::search($query, ['question', 'answer'])
            ->orderBy('order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find($id)
    {
        return Faq::findOrFail($id);
    }

    public function create(array $data)
    {
        return Faq::create($data);
    }

    public function update($id, array $data)
    {
        $faq = $this->find($id);
        $faq->update($data);

        return $faq;
    }

    public function delete($id)
    {
        return $this->find($id)->delete();
    }

    /**
     * Ordered by `order` then id.
     *
     * The id tie-break is not decoration: two entries sharing an order number
     * would otherwise come back in whatever sequence the database felt like, and
     * a help list that reshuffles between visits looks broken.
     *
     * @return Builder<Faq>
     */
    private function ordered()
    {
        return Faq::query()->orderBy('order')->orderBy('id');
    }
}
