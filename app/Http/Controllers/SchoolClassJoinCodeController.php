<?php

namespace App\Http\Controllers;

use App\Actions\Classes\ManageSchoolClassJoinCode;
use App\Http\Requests\Classes\ManageSchoolClassJoinCodeRequest;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;

class SchoolClassJoinCodeController extends Controller
{
    public function update(ManageSchoolClassJoinCodeRequest $request, SchoolClass $schoolClass, ManageSchoolClassJoinCode $manage): RedirectResponse
    {
        match ($request->validated('action')) {
            'regenerate' => $manage->regenerate($schoolClass),
            'enable' => $manage->enable($schoolClass),
            'disable' => $manage->disable($schoolClass),
        };

        return back()->with('success', 'Class join code updated.');
    }
}
