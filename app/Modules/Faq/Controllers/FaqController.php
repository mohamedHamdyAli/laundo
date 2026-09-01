<?php

namespace App\Modules\Faq\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Faq\Requests\FaqRequest;
use App\Modules\Faq\Services\faqCrudService;
use Illuminate\Http\Request;

/**
 * «الأسئلة الشائعة» — the content behind the first item under «المساعدة والدعم».
 *
 * Ordinary CRUD, matching the shape of the other content modules. The only thing
 * worth knowing is `audience`: the driver app shows the same section as the
 * customer app and the answers are not the same, so an entry can be aimed at one
 * or shared by both.
 */
class FaqController extends Controller
{
    public function __construct(private readonly faqCrudService $faqService) {}

    public function index(Request $request)
    {
        $faqs = $this->faqService->getAllFaqs();

        $view = view('admin.faq.index', compact('faqs'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $faqs = $this->faqService->searchFaqs($request->get('query'));

        return response()->json([
            'table' => view('admin.faq.partials._faq_table_body', compact('faqs'))->render(),
            // toHtml(), not a string cast: links() returns an Htmlable.
            'pagination' => $faqs->withQueryString()->links()->toHtml(),
        ]);
    }

    public function create()
    {
        return view('admin.faq.create', $this->faqService->shredData());
    }

    public function store(FaqRequest $request)
    {
        $this->faqService->addFaq($request->validated());

        return redirect()->route('admin.faq.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        return view('admin.faq.show', $this->faqService->shredData($id));
    }

    public function edit($id)
    {
        return view('admin.faq.edit', $this->faqService->shredData($id));
    }

    public function update(FaqRequest $request, $id)
    {
        $this->faqService->updateFaq($request->validated() + ['id' => $id]);

        return redirect()->route('admin.faq.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->faqService->deleteFaq($id);

        return redirect()->route('admin.faq.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $faq = $this->faqService->toggleStatus($id, $request->status);

        return response()->json(['success' => true, 'status' => $faq->status]);
    }
}
