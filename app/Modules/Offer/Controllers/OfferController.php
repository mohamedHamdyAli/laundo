<?php

namespace App\Modules\Offer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Offer\Requests\OfferRequest;
use App\Modules\Offer\Services\offerCrudService;
use Illuminate\Http\Request;

/**
 * HTTP only — every rule lives in the service.
 */
class OfferController extends Controller
{
    public function __construct(private readonly offerCrudService $offerService) {}

    public function index(Request $request)
    {
        $offers = $this->offerService->getAllOffers();
        $view = view('admin.offer.index', compact('offers'));

        return $request->ajax() ? response($view) : $view;
    }

    /**
     * AJAX only. Returns the rendered rows and the pagination markup, which is
     * what `setupAjaxSearch` swaps in.
     */
    public function search(Request $request)
    {
        if ($request->ajax()) {
            // `$request->get('query')`, never `$request->query` — that is
            // Symfony's own ParameterBag property.
            $offers = $this->offerService->searchOffers($request->get('query'));
            $table = view('admin.offer.partials._offer_table_body', compact('offers'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $offers->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.offer.create', $this->offerService->shredData());
    }

    public function store(OfferRequest $request)
    {
        $this->offerService->addOffer($request->validated());

        return redirect()->route('admin.offer.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.offer.show', $this->offerService->shredData($id));
    }

    public function edit(string $id)
    {
        return view('admin.offer.edit', $this->offerService->shredData($id));
    }

    public function update(OfferRequest $request, $id)
    {
        $this->offerService->updateOffer($request->validated() + ['id' => $id]);

        return redirect()->route('admin.offer.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->offerService->deleteOffer($id);

        return redirect()->route('admin.offer.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $offer = $this->offerService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $offer->status,
        ]);
    }
}
