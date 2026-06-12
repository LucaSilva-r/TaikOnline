<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserAccessCodeBindRequest;
use App\Http\Requests\Admin\UserPasswordUpdateRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use App\Services\AccessCodeOwnershipService;
use App\Services\CardIssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::query()
                ->latest('id')
                ->paginate(25)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'created_at' => optional($user->created_at)->toDateTimeString(),
                ]),
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ]),
        ]);
    }

    public function edit(User $user): Response
    {
        $accessCode = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $user->id))
            ->value('access_code');

        return Inertia::render('admin/UserEdit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'created_at' => optional($user->created_at)->toDateTimeString(),
            ],
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ]),
            'accessCode' => $accessCode,
        ]);
    }

    public function updatePassword(UserPasswordUpdateRequest $request, User $user): RedirectResponse
    {
        $user->update(['password' => $request->validated('password')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }

    public function bindAccessCode(UserAccessCodeBindRequest $request, User $user, AccessCodeOwnershipService $accessCodes): RedirectResponse
    {
        $accessCodes->claim($user, $request->validated('access_code'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code linked.')]);

        return back();
    }

    public function unbindAccessCode(User $user, AccessCodeOwnershipService $accessCodes): RedirectResponse
    {
        $accessCodes->unclaim($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code unlinked.')]);

        return back();
    }

    public function rotateAccessCode(User $user, CardIssueService $cards): RedirectResponse
    {
        $player = Player::query()->where('user_id', $user->id)->first();

        if (! $player instanceof Player) {
            throw ValidationException::withMessages([
                'access_code' => __('This user does not have a linked access code.'),
            ]);
        }

        try {
            $cards->rotate($player);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'access_code' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code rotated.')]);

        return back();
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($user->id === $request->user()->id && $data['role'] !== UserRole::Admin->value) {
            return back()->withErrors(['role' => 'You cannot demote yourself.']);
        }

        if ($user->email !== $data['email']) {
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();

        return to_route('admin.users.index');
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        if ($user->id === $request->user()->id && $data['role'] !== UserRole::Admin->value) {
            return back()->withErrors(['role' => 'You cannot demote yourself.']);
        }

        $user->update(['role' => $data['role']]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete yourself.']);
        }

        $user->delete();

        return back();
    }
}
