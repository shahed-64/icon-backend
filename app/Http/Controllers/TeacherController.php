<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);
        $perPage = min(max($perPage, 1), 100);

        // কুয়েরি বিল্ডার ও শিফট রিলেশন ইগার লোডিং
        $query = Teacher::with('shifts');

        // সার্চ ফিল্টার (নাম, আইডি বা ইমেইল দিয়ে)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('teacher_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // ডিপার্টমেন্ট ফিল্টার
        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        $teachers = $query->latest()->paginate($perPage);

        // ইমেজের ফুল পাথ যুক্ত করা
        $teachers->getCollection()->transform(function ($teacher) {
            if ($teacher->image && !str_starts_with($teacher->image, 'http')) {
                $teacher->image = asset('storage/' . $teacher->image);
            }
            return $teacher;
        });

        return response()->json([
            'status'     => true,
            'data'       => $teachers->items(),
            'pagination' => [
                'current_page' => $teachers->currentPage(),
                'last_page'    => $teachers->lastPage(),
                'per_page'     => $teachers->perPage(),
                'total'        => $teachers->total(),
                'from'         => $teachers->firstItem(),
                'to'           => $teachers->lastItem(),
            ],
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'    => 'required|string|max:255',
            'designation'  => 'required|string|max:255',
            'department'   => 'required|string|max:255',
            'qualification'=> 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'email'        => 'required|email|unique:teachers,email',
            'joining_date' => 'required|date',
            'salary'       => 'nullable|numeric',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'shift_ids'    => 'nullable|array',
            'shift_ids.*'  => 'exists:shifts,id',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $imagePath = $file->storeAs('teachers', $filename, 'public');
        }

        // Teacher ID Generate
        $lastTeacher = Teacher::latest('id')->first();
        if ($lastTeacher && $lastTeacher->teacher_id) {
            $lastNumber = (int) str_replace('TCH-', '', $lastTeacher->teacher_id);
            $teacherId = 'TCH-' . ($lastNumber + 1);
        } else {
            $teacherId = 'TCH-1001';
        }

        $teacher = Teacher::create([
            'teacher_id'   => $teacherId,
            'full_name'    => $request->full_name,
            'designation'  => $request->designation,
            'department'   => $request->department,
            'qualification'=> $request->qualification,
            'phone'        => $request->phone,
            'email'        => $request->email,
            'join_date'    => $request->joining_date ?? now()->toDateString(),
            'salary'       => $request->salary ?? 0,
            'image'        => $imagePath,
        ]);

        $teacher->shifts()->sync($request->shift_ids ?? []);
        $teacher->load('shifts');

        if ($teacher->image) {
            $teacher->image = asset('storage/' . $teacher->image);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Teacher added successfully!',
            'data'    => $teacher
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Teacher $teacher)
    {
        $teacher->load('shifts');

        if ($teacher->image && !str_starts_with($teacher->image, 'http')) {
            $teacher->image = asset('storage/' . $teacher->image);
        }

        return response()->json([
            'status' => true,
            'data'   => $teacher
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Teacher $teacher)
    {
        $validated = $request->validate([
            'full_name'    => 'required|string|max:255',
            'designation'  => 'required|string|max:255',
            'department'   => 'required|string|max:255',
            'qualification'=> 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'email'        => 'required|email|unique:teachers,email,' . $teacher->id,
            'joining_date' => 'nullable|date',
            'join_date'    => 'nullable|date',
            'salary'       => 'nullable|numeric',
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'shift_ids'    => 'nullable|array',
            'shift_ids.*'  => 'exists:shifts,id',
        ]);

        $imagePath = $teacher->image;

        if ($request->hasFile('image')) {
            if ($teacher->image && Storage::disk('public')->exists($teacher->image)) {
                Storage::disk('public')->delete($teacher->image);
            }

            $file = $request->file('image');
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $imagePath = $file->storeAs('teachers', $filename, 'public');
        }

        $teacher->update([
            'full_name'    => $request->full_name,
            'designation'  => $request->designation,
            'department'   => $request->department,
            'qualification'=> $request->qualification,
            'phone'        => $request->phone,
            'email'        => $request->email,
            'join_date'    => $request->joining_date ?? $request->join_date ?? $teacher->join_date,
            'salary'       => $request->salary ?? $teacher->salary,
            'image'        => $imagePath,
        ]);

        $teacher->shifts()->sync($request->shift_ids ?? []);
        $teacher->load('shifts');

        if ($teacher->image && !str_starts_with($teacher->image, 'http')) {
            $teacher->image = asset('storage/' . $teacher->image);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Teacher updated successfully!',
            'data'    => $teacher
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Teacher $teacher)
    {
        if ($teacher->image && Storage::disk('public')->exists($teacher->image)) {
            Storage::disk('public')->delete($teacher->image);
        }

        $teacher->shifts()->detach();
        $teacher->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Teacher deleted successfully!'
        ], 200);
    }
}
