<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Requests\UserRequest;
use App\Modules\User\Services\userCrudService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly userCrudService $userService) {}

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
        // `row`, not `user`: every shared form partial in the panel reads the
        // record as `$row`, and this module's own formInput does so nine times.
        // Passing it as `user` left `$row` undefined — the show screen was a
        // 500 and the edit screen silently rendered an empty form, which on
        // submit would have written the blanks back over the record.
        return view('admin.user.show', ['row' => $this->userService->getUser($id)]);
    }

    public function edit($id)
    {
        return view('admin.user.edit', ['row' => $this->userService->getUser($id)]);
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
