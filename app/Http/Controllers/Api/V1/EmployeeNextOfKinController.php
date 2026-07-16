<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Models\Employee;
use App\Models\EmployeeNextOfKin;
use Illuminate\Http\Request;

class EmployeeNextOfKinController extends Controller
{
    use FindsOrganizationEmployee;

    public function show(int $employee)
    {
        $this->findOrgEmployee($employee);
        $nok = EmployeeNextOfKin::where('employee_id', $employee)->first();

        return response()->json($nok ?? (object) []);
    }

    public function upsert(Request $request, int $employee)
    {
        $this->findOrgEmployee($employee);
        $data = $request->validate([
            'full_name' => 'required|string|max:200',
            'relationship' => 'nullable|string|max:100',
            'national_id' => 'nullable|string|max:45',
            'phone' => 'required|string|max:45',
            'address' => 'nullable|string|max:500',
        ]);
        $data['employee_id'] = $employee;

        $nok = EmployeeNextOfKin::updateOrCreate(
            ['employee_id' => $employee],
            $data,
        );

        return response()->json($nok);
    }

    public function destroy(int $employee)
    {
        EmployeeNextOfKin::where('employee_id', $employee)->delete();

        return response()->json(null, 204);
    }
}
