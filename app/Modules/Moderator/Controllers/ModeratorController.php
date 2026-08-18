<?php

namespace App\Modules\Moderator\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Moderator\Requests\ModeratorRequest;
use App\Modules\Moderator\Services\moderatorCrudService;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ModeratorController extends Controller
{
    public function __construct(private readonly moderatorCrudService $moderatorCrudService)
    {
    }

    public function index(Request $request)
    {
        $moderators = $this->moderatorCrudService->getAllPaginated(10);
        $view = view('admin.moderator.index', compact('moderators'));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $moderators = $this->moderatorCrudService->search($request->get('query'), 10);

            $table = view('admin.moderator.partials._moderator_table_body', compact('moderators'))->render();

            return response()->json([
                'table' => $table,
                'pagination' => (string) $moderators->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        $data = $this->moderatorCrudService->shredData();
        return view('admin.moderator.create', $data);
    }

    public function store(ModeratorRequest $request)
    {
        $moderator = $this->moderatorCrudService->addNew($request->validated());

        $recipients = User::whereHas(
            'role',
            fn ($q) => $q->whereIn('slug', ['super_admin', 'admin'])
        )->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AdminNotification(
                __('New Moderator Added'),
                __(':name was added as a moderator.', ['name' => $moderator->name]),
                route('admin.moderator.show', $moderator->id),
            ));
        }

        return redirect()->route('admin.moderator.index')->with('success', __('Added Successfully'));
    }

    public function show($id)
    {
        $data = $this->moderatorCrudService->shredData($id);
        return view('admin.moderator.show', $data);
    }

    public function edit($id)
    {
        $data = $this->moderatorCrudService->shredData($id);
        return view('admin.moderator.edit', $data);
    }

    public function update(ModeratorRequest $request, $id)
    {
        $this->moderatorCrudService->updateRecord($request->validated() + ['id' => $id]);

        return redirect()->route('admin.moderator.index')->with('success', __('Updated Successfully'));
    }

    public function destroy($id)
    {
        $this->moderatorCrudService->deleteRecord($id);

        return redirect()->route('admin.moderator.index')->with('success', __('Deleted Successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $moderator = $this->moderatorCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $moderator->status,
        ]);
    }
}
