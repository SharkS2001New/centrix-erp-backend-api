<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AiFormRequestHelper
{
    /**
     * Resolve and validate a FormRequest outside the HTTP pipeline so controllers can call validated().
     */
    public static function prepare(FormRequest $request, User $user): FormRequest
    {
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        return $request;
    }
}
