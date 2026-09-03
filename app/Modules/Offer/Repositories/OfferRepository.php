<?php

namespace App\Modules\Offer\Repositories;

use App\Modules\Offer\Models\Offer;

/**
 * The only place raw Eloquent for offers lives.
 */
class OfferRepository
{
    public function getAll($perPage = 10)
    {
        // `coupon` eagerly, because the list shows each offer's badge and the
        // badge comes off the coupon — without it a page of ten offers is ten
        // extra queries.
        return Offer::with('coupon')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return Offer::with('coupon')
            ->search($query, ['title', 'description'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find($id)
    {
        return Offer::with('coupon')->findOrFail($id);
    }

    public function create(array $data)
    {
        return Offer::create($data);
    }

    public function update($id, array $data)
    {
        $offer = Offer::findOrFail($id);
        $offer->update($data);

        return $offer;
    }

    public function delete($id)
    {
        $offer = Offer::findOrFail($id);

        // The file goes with the row. Left behind it is an orphan nothing will
        // ever reference or clean up.
        DeleteImage($offer->image);

        $offer->delete();

        return true;
    }
}
