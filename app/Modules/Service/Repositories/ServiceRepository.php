<?php

namespace App\Modules\Service\Repositories;

use App\Modules\Service\Models\Service;

class ServiceRepository
{
    public function getAllPaginated($perPage = 10)
    {
        return Service::orderBy('sort_order')->orderBy('id')->paginate($perPage);
    }

    /**
     * The Services list search.
     *
     * The DURATION column reads "24-48 hours", and pasting that returned nothing:
     * no column holds it. `durationLabel()` composes the range from
     * `duration_min` and `duration_max`, and the unit word comes from the Web
     * File — so the cell is a sentence assembled from three sources.
     *
     * Both halves are handled. The range is rebuilt in SQL as a composed
     * expression, and the unit word is stripped from the term first, so the whole
     * cell, the bare range, or either number all find the row.
     */
    public function search($query, $perPage = 10)
    {
        $term = $this->withoutDurationUnit((string) $query);

        return Service::search(
            $term,
            ['name', 'description', 'pricing_mode', 'duration_unit', 'sort_order'],
            [
                // Rebuilds "24-48". COALESCE because either bound may be null,
                // and `searchConcat` because SQLite has no CONCAT while MySQL
                // reads `||` as logical OR — the scope folds the dash and the
                // case on both sides of the comparison.
                Service::searchConcat([
                    "COALESCE(`duration_min`, '')",
                    "'-'",
                    "COALESCE(`duration_max`, '')",
                ]),
                // The column stores the singular (`hour`) while the table renders
                // the plural, so searching the word somebody can actually see
                // found nothing. Both are regular, so appending an `s` is enough,
                // and the bare `duration_unit` column stays in the list above so
                // the singular still matches too.
                Service::searchConcat(['`duration_unit`', "'s'"]),
            ]
        )->orderBy('sort_order')->paginate($perPage);
    }

    /**
     * Drop a trailing unit word, but only from a term that also has a number in
     * it.
     *
     * That condition is the whole trick. "24-48 hours" is a range plus noise and
     * the noise has to go, but a bare "hours" is somebody searching the unit
     * itself — and stripping it would leave an empty term, which matches every
     * row. So a term with no digits is left exactly as typed and reaches
     * `duration_unit` untouched.
     *
     * Both languages, because the unit is rendered from the Web File and an
     * Arabic reader pastes «24-48 ساعة».
     */
    private function withoutDurationUnit(string $term): string
    {
        if (preg_match('/\d/', $term) !== 1) {
            return $term;
        }

        $units = ['hours', 'hour', 'days', 'day', 'ساعات', 'ساعة', 'أيام', 'يوم'];

        return trim(str_ireplace($units, '', $term));
    }

    public function findById($id)
    {
        return Service::findOrFail($id);
    }

    /**
     * Active, per-item services in display order — the columns of the price grid,
     * and the set the customer app lists in wizard step 2.
     */
    public function activePerItem()
    {
        return Service::where('status', 'active')
            ->where('pricing_mode', 'per_item')
            ->orderBy('sort_order')
            ->get();
    }

    public function allActive()
    {
        return Service::where('status', 'active')->orderBy('sort_order')->get();
    }

    public function create(array $data)
    {
        return Service::create($data);
    }

    public function update($id, array $data)
    {
        $service = $this->findById($id);
        $service->update($data);

        return $service;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }
}
