<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use Illuminate\Http\Request;

class EmployeeEmergencyContactController extends Controller
{
    use FindsOrganizationEmployee;

    public function index(int $employee)
    {
        $this->findOrgEmployee($employee);

        return response()->json(
            EmployeeEmergencyContact::where('employee_id', $employee)->orderByDesc('is_primary')->get()
        );
    }

    public function store(Request $request, int $employee)
    {
        $this->findOrgEmployee($employee);
        $data = $request->validate([
            'full_name' => 'required|string|max:200',
            'relationship' => 'nullable|string|max:100',
            'phone' => 'required|string|max:45',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'is_primary' => 'nullable|boolean',
        ]);
        $data['employee_id'] = $employee;

        if (! empty($data['is_primary'])) {
            EmployeeEmergencyContact::where('employee_id', $employee)->update(['is_primary' => false]);
        }

        return response()->json(EmployeeEmergencyContact::create($data), 201);
    }

    public function update(Request $request, int $employee, int $contact)
    {
        $row = EmployeeEmergencyContact::where('employee_id', $employee)->findOrFail($contact);
        $data = $request->validate([
            'full_name' => 'sometimes|string|max:200',
            'relationship' => 'nullable|string|max:100',
            'phone' => 'sometimes|string|max:45',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'is_primary' => 'nullable|boolean',
        ]);

        if (! empty($data['is_primary'])) {
            EmployeeEmergencyContact::where('employee_id', $employee)->update(['is_primary' => false]);
        }

        $row->update($data);

        return response()->json($row->fresh());
    }

    public function destroy(int $employee, int $contact)
    {
        EmployeeEmergencyContact::where('employee_id', $employee)->findOrFail($contact)->delete();

        return response()->json(null, 204);
    }
}
