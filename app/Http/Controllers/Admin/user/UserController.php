<?php

namespace App\Http\Controllers\Admin\user;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\user\UserRequest;
use App\Models\User;
use App\Services\user\userCrudService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private userCrudService $userCrudService;
    public function __construct(userCrudService $userCrudService)
    {
        $this->userCrudService = $userCrudService;
    }
    public function index()
    {
        $users = User::availableUsers()->paginate(10);
        return view('admin.user.index', compact('users'));
    }
    public function search(Request $request)
    {
        if ($request->ajax()) {
            $searchQuery = $request->get('query');
            $users = User::availableUsers()->search($searchQuery, ['name', 'phone'])->paginate(10);
            $table = view('admin.user.partials._user_table_body', compact('users'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $users->withQueryString()->links(),
            ]);
        }
    }


    public function create()
    {
        $data = $this->userCrudService->shredData();
        return view('admin.user.create', $data);
    }

    public function store(UserRequest $request)
    {
        $this->userCrudService->addNew($request->validated());
        return redirect()->route('admin.user.index')->with('success', __('added_successfully'));
    }

    public function show($id)
    {
        $data = $this->userCrudService->shredData($id);
        return view('admin.user.show', $data);
    }
    public function edit($id)
    {
        $data = $this->userCrudService->shredData($id);
        return view('admin.user.edit', $data);
    }

    public function update(UserRequest $request, $id)
    {
        $this->userCrudService->updateRecord($request->validated() + ['id' => $id]);
        return redirect()->route('admin.user.index')->with('success', __('updated_successfully'));
    }

    public function destroy($id)
    {
        $this->userCrudService->deleteRecord($id);
        return redirect()->route('admin.user.index')->with('success', __('deleted_successfully'));
    }
    public function toggleStatus(Request $request, $id)
    {
        $user = $this->userCrudService->toggleStatus($id, $request->status);

        return response()->json([
            'success' => true,
            'status' => $user->status,
        ]);
    }
}
