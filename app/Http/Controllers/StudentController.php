<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class StudentController extends Controller
{
    /**
     * Display all students
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = min(max($perPage, 1), 100);

        $search = trim($request->get('search', ''));
        $classId = $request->get('class_id');

        // একসাথে relations এবং payments লোড করা (N+1 এবং ডাবল কুয়েরি এড়াতে)
        $query = Student::with([
            'section',
            'classInfo',
            'classGroup',
            'shift',
            'payments' => function ($q) {
                $q->select(
                    'id',
                    'student_id',
                    'month',
                    'paid_amount',
                    'due_amount',
                    'status'
                );
            }
        ])->orderBy('id', 'desc');

        /**
         * --------------------------------------------------------------------------
         * Search
         * --------------------------------------------------------------------------
         */
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('student_id', 'like', '%' . $search . '%');
            });
        }

        /**
         * --------------------------------------------------------------------------
         * Class Filter
         * --------------------------------------------------------------------------
         */
        if (!empty($classId)) {
            $query->where('class_id', $classId);
        }

        /**
         * --------------------------------------------------------------------------
         * Pagination
         * --------------------------------------------------------------------------
         */
        $students = $query->paginate($perPage);

        /**
         * --------------------------------------------------------------------------
         * Optimized Due / Available Months Calculation
         * --------------------------------------------------------------------------
         */
        $allMonths = [
            'January',
            'February',
            'March',
            'April',
            'May',
            'June',
            'July',
            'August',
            'September',
            'October',
            'November',
            'December'
        ];

        $currentMonth = Carbon::now()->month;

        foreach ($students->items() as $student) {
            $paidMonths = $student->payments->pluck('month')->toArray();

            $admissionMonth = $student->admission_date
                ? Carbon::parse($student->admission_date)->month
                : 1;

            $monthsTillNow = array_slice(
                $allMonths,
                $admissionMonth - 1,
                max(0, $currentMonth - $admissionMonth + 1)
            );

            $dueMonths = array_values(
                array_diff($monthsTillNow, $paidMonths)
            );

            $monthsTillDecember = array_slice(
                $allMonths,
                $admissionMonth - 1
            );

            $availableMonths = array_values(
                array_diff($monthsTillDecember, $paidMonths)
            );

            $student->setAttribute('due_months', $dueMonths);
            $student->setAttribute('available_months', $availableMonths);
        }

        $totalStudents = Student::count();

        return response()->json([
            'status' => true,
            'students' => $students->items(),
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'from' => $students->firstItem(),
                'to' => $students->lastItem(),
            ],
            'total_students' => $totalStudents,
        ]);
    }

    /**
     * Store a newly created student
     */
    public function store(Request $request)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'fathers_name' => 'required|string|max:255',
            'mothers_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'section_id' => 'required|exists:sections,id',
            'class_id' => 'required|exists:clss_m_s,id',
            'class_group_id' => 'required|exists:class_groups,id',
            'course_name' => 'nullable|string|max:100',
            'admission_date' => 'nullable|date',
            'email' => 'required|email|unique:students,email',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'shift_id' => 'required|exists:shifts,id',
            'monthly_fee' => 'nullable|numeric',
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $filename = time() . '_' .
                Str::random(10) . '.' .
                $file->getClientOriginalExtension();

            $imagePath = $file->storeAs(
                'students',
                $filename,
                'public'
            );
        }

        $lastStudent = Student::latest('id')->first();

        if ($lastStudent && $lastStudent->student_id) {
            $lastNumber = (int) str_replace(
                'STD-',
                '',
                $lastStudent->student_id
            );

            $studentId = 'STD-' . ($lastNumber + 1);
        } else {
            $studentId = 'STD-1001';
        }

        $student = Student::create([
            'full_name' => $request->full_name,
            'version' => $request->version,
            'fathers_name' => $request->fathers_name,
            'mothers_name' => $request->mothers_name,
            'student_id' => $studentId,
            'phone' => $request->phone,
            'section_id' => $request->section_id,
            'class_id' => $request->class_id,
            'class_group_id' => $request->class_group_id,
            'course_name' => $request->course_name,
            'admission_date' => $request->admission_date ?? now()->toDateString(),
            'email' => $request->email,
            'image' => $imagePath,
            'shift_id' => $request->shift_id,
            'monthly_fee' => $request->monthly_fee,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Student Created Successfully',
            'student' => $student->load([
                'section',
                'classInfo',
                'classGroup',
                'shift'
            ])
        ], 201);
    }

    /**
     * Display the specified student
     */
    public function show(Student $student)
    {
        return response()->json([
            'status' => true,
            'student' => $student->load([
                'payments',
                'section',
                'classInfo',
                'classGroup',
                'shift'
            ])
        ]);
    }

    /**
     * Edit student
     */
    public function edit(Student $student)
    {
        return response()->json([
            'status' => true,
            'student' => $student->load([
                'section',
                'classInfo',
                'classGroup',
                'shift'
            ])
        ]);
    }

    /**
     * Update student
     */
    public function update(Request $request, Student $student)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'phone' => 'required|string|max:20',
            'section_id' => 'required|exists:sections,id',
            'class_id' => 'required|exists:clss_m_s,id',
            'class_group_id' => 'required|exists:class_groups,id',
            'course_name' => 'nullable|string|max:100',
            'admission_date' => 'required|date',
            'email' => [
                'required',
                'email',
                Rule::unique('students', 'email')->ignore($student->id)
            ],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'shift_id' => 'required|exists:shifts,id',
            'monthly_fee' => 'nullable|numeric',
        ]);

        $imagePath = $student->image;

        if ($request->hasFile('image')) {

            if (
                $student->image &&
                Storage::disk('public')->exists($student->image)
            ) {
                Storage::disk('public')->delete($student->image);
            }

            $file = $request->file('image');

            $filename = time() . '_' .
                Str::random(10) . '.' .
                $file->getClientOriginalExtension();

            $imagePath = $file->storeAs(
                'students',
                $filename,
                'public'
            );
        }

        $student->update([
            'full_name' => $request->full_name,
            'version' => $request->version,
            'phone' => $request->phone,
            'section_id' => $request->section_id,
            'class_id' => $request->class_id,
            'class_group_id' => $request->class_group_id,
            'course_name' => $request->course_name,
            'admission_date' => $request->admission_date,
            'email' => $request->email,
            'image' => $imagePath,
            'shift_id' => $request->shift_id,
            'monthly_fee' => $request->monthly_fee ?? $student->monthly_fee,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Student Updated Successfully',
            'student' => $student->load([
                'section',
                'classInfo',
                'classGroup',
                'shift'
            ])
        ]);
    }

    /**
     * Delete student
     */
    public function destroy(Student $student)
    {
        if (
            $student->image &&
            Storage::disk('public')->exists($student->image)
        ) {
            Storage::disk('public')->delete($student->image);
        }

        $student->payments()->delete();
        $student->delete();

        return response()->json([
            'status' => true,
            'message' => 'Student and related payments deleted successfully'
        ]);
    }

    /**
     * Student Payments
     */
    public function studentPayments($id)
    {
        $payments = Payment::with('student')
            ->where('student_id', $id)
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'payments' => $payments
        ]);
    }
}
