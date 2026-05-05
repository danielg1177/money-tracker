<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function users()
    {
        return User::with('family')->get();
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'family_id' => 'nullable|exists:families,id',
            'role' => ['required', Rule::in(['member', 'head_of_household'])],
            'is_admin' => 'boolean',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'family_id' => $request->family_id,
            'role' => $request->role,
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return $user->load('family');
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'family_id' => 'nullable|exists:families,id',
            'role' => ['required', Rule::in(['member', 'head_of_household'])],
            'password' => 'nullable|string|min:8',
            'is_admin' => 'boolean',
        ]);

        $attributes = $request->only(['name', 'email', 'family_id', 'role', 'is_admin']);

        if ($request->filled('password')) {
            $attributes['password'] = Hash::make($request->string('password')->value());
        }

        $user->update($attributes);

        return $user->load('family');
    }

    public function deleteUser(User $user)
    {
        if ($user->id === auth()->id()) {
            abort(403, 'Cannot delete yourself.');
        }

        $user->delete();

        return response()->noContent();
    }

    public function families()
    {
        return Family::with('users', 'categories')->get();
    }

    public function createFamily(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        return Family::create($request->only(['name', 'description']));
    }

    public function updateFamily(Request $request, Family $family)
    {
        if (auth()->user()->role === 'head_of_household' && $family->id !== auth()->user()->family_id) {
            abort(403, 'You can only manage your own family.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $family->update($request->only(['name', 'description']));

        return $family->load('users', 'categories');
    }

    public function deleteFamily(Family $family)
    {
        if (auth()->user()->role === 'head_of_household' && $family->id !== auth()->user()->family_id) {
            abort(403, 'You can only manage your own family.');
        }

        User::where('family_id', $family->id)->update(['family_id' => null]);
        $family->delete();

        return response()->noContent();
    }

    public function addFamilyMember(Request $request, Family $family)
    {
        if (auth()->user()->role === 'head_of_household' && $family->id !== auth()->user()->family_id) {
            abort(403, 'You can only manage your own family.');
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        User::find($request->user_id)->update(['family_id' => $family->id]);

        return $family->load('users');
    }

    public function removeFamilyMember(Family $family, User $user)
    {
        if (auth()->user()->role === 'head_of_household' && $family->id !== auth()->user()->family_id) {
            abort(403, 'You can only manage your own family.');
        }

        $user->update(['family_id' => null]);

        return $family->load('users');
    }

    public function myFamily()
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'You are not in a family.'], 404);
        }

        return $user->family->load('users', 'categories');
    }
}
