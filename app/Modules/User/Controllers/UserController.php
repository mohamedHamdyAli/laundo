<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Requests\UserRequest;
use App\Modules\User\Services\userCrudService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private userCrudService $userService;

    public function __construct(userCrudService $userService)
    {
        $this->userService = $userService;
    }

    public function index()
    {
        $users = $this->userService->getAllUsers();
        return view('admin.user.index', compact('users'));
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $users = $this->userService->searchUsers($request->get('query'));
            $table = view('admin.user.partials._user_table_body', compact('users'))->render();
            return response()->json([
                'table' => $table,
                'pagination' => (string) $users->withQueryString()->links(),
            ]);
        }
    }

    public function create()
    {
        return view('admin.user.create');
    }

    public function store(UserRequest $request)
    {
        $this->userService->addUser($request->validated());
        return redirect()->route('admin.user.index')->with('success', __('added_successfully'));
    }

    public function show($id)
    {
        $user = $this->userService->getUser($id);
        return view('admin.user.show', compact('user'));
    }

    public function edit($id)
    {
        $user = $this->userService->getUser($id);
        return view('admin.user.edit', compact('user'));
    }

    public function update(UserRequest $request, $id)
    {
        $this->userService->updateUser($request->validated() + ['id' => $id]);
        return redirect()->route('admin.user.index')->with('success', __('updated_successfully'));
    }

    public function destroy($id)
    {
        $this->userService->deleteUser($id);
        return redirect()->route('admin.user.index')->with('success', __('deleted_successfully'));
    }

    public function toggleStatus(Request $request, $id)
    {
        $user = $this->userService->toggleStatus($id, $request->status);
        return response()->json([
            'success' => true,
            'status' => $user->status,
        ]);
    }
}
