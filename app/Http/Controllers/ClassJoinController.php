<?php

namespace App\Http\Controllers;

use App\Actions\Classes\JoinSchoolClassByCode;
use App\Http\Requests\Classes\JoinSchoolClassRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ClassJoinController extends Controller
{
    public function store(JoinSchoolClassRequest $request, JoinSchoolClassByCode $join): RedirectResponse
    {
        try {
            $schoolClass = $join->handle($request->user(), $request->validated('code'));
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return redirect()->route('classes.show', $schoolClass)->with('success', 'You have joined the class.');
    }
}
