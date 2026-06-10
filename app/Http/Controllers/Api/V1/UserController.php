<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $rules['password'] = 'required|string|min:6';
        $data = $request->validate($rules);
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $model = User::create($data);
        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = User::findOrFail($id);
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $data = $request->validate($rules);
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $model->update($data);
        return response()->json($model);
    }

    public function destroy(string $id)
    {
        $model = User::findOrFail($id);
        $authUser = request()->user();

        if ($authUser && (int) $authUser->id === (int) $model->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $model->delete();

        return response()->json(null, 204);
    }
}
