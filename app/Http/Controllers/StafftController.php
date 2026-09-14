<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\Student;
use App\Models\Payment;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StafftController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json([
            'status' => true,
            'staff'  => Staff::with('shift')->orderBy('id', 'desc')->get()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required',
            'user_name' => 'required',
            'skill'     => 'required',
            'role'      => 'required',
            'shift_id'  => 'required|exists:shifts,id',
            'email'     => 'required|email|unique:staff,email',
            'password'  => 'required|confirmed',
            'image'     => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'salary'    => 'nullable|numeric',
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('staffs', 'public');
        }

        $staff = Staff::create([
            'name'      => $request->name,
            'user_name' => $request->user_name,
            'skill'     => $request->skill,
            'role'      => $request->role,
            'shift_id'  => $request->shift_id,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'image'     => $imagePath,
            'salary'    => $request->salary ?? 0,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Staff Created Successfully',
            'staff'   => $staff->load('shift')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Staff $staff)
    {
        return response()->json([
            'status' => true,
            'staff'  => $staff->load('shift')
        ]);
    }

    /**
     * Show the form data for editing the specified resource.
     */
    public function edit(Staff $staff)
    {
        return response()->json([
            'status' => true,
            'staff'  => $staff->load('shift')
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Staff $staff)
    {
        $request->validate([
            'name'       => 'required',
            'user_name'  => 'required',
            'skill'      => 'required',
            'role'       => 'required',
            'shift_id'   => 'required|exists:shifts,id',
            'email'      => 'required|email|unique:staff,email,' . $staff->id,
            'password'   => 'nullable|confirmed',
            'image'      => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'salary'     => 'nullable|numeric',
        ]);

        $data = [
            'name'      => $request->name,
            'user_name' => $request->user_name,
            'skill'     => $request->skill,
            'role'      => $request->role,
            'shift_id'  => $request->shift_id,
            'email'     => $request->email,
            'salary'    => $request->salary ?? $staff->salary,
        ];

        // Replace old image with the new one
        if ($request->hasFile('image')) {

            if ($staff->image && Storage::disk('public')->exists($staff->image)) {
                Storage::disk('public')->delete($staff->image);
            }

            $data['image'] = $request->file('image')->store('staffs', 'public');
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $staff->update($data);

        return response()->json([
            'status'  => true,
            'message' => 'Staff updated successfully',
            'staff'   => $staff->load('shift')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Staff $staff)
    {
        if ($staff->image && Storage::disk('public')->exists($staff->image)) {
            Storage::disk('public')->delete($staff->image);
        }

        $staff->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Staff deleted successfully'
        ]);
    }

    /**
     * Login Section
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        $staff = Staff::where('email', $request->email)->first();

        if (!$staff || !Hash::check($request->password, $staff->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $staff->createToken('staff-token')->plainTextToken;

        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'staff'   => $staff->load('shift'),
            'token'   => $token
        ]);
    }

    /**
     * Dashboard
     */
    public function dashboard(Request $request)
    {
        $totalStaff      = Staff::count();
        $totalStudents   = Student::count();
        $totalPayments   = Payment::count();
        $totalTeachers   = Teacher::count();
        $totalCollection = Payment::sum('paid_amount');

        $recentStaff = Staff::with('shift')
            ->latest()
            ->take(5)
            ->get();

        $monthlyCollection = Payment::select(
            DB::raw('MONTH(payment_date) as month'),
            DB::raw('SUM(paid_amount) as total')
        )
            ->whereYear('payment_date', now()->year)
            ->groupBy(DB::raw('MONTH(payment_date)'))
            ->orderBy('month')
            ->get();

        $authUser = $request->user();

        return response()->json([
            'status'             => true,
            'total_staff'        => $totalStaff,
            'total_students'     => $totalStudents,
            'total_payments'     => $totalPayments,
            'total_teachers'     => $totalTeachers,
            'total_collection'   => $totalCollection,
            'recent_staff'       => $recentStaff,
            'monthly_collection' => $monthlyCollection,

            'user' => $authUser ? [
                'name'        => $authUser->name,
                'role'        => $authUser->role,
                'designation' => $authUser->skill ?? $authUser->role,
                'image'       => $authUser->image
                    ? asset('storage/' . $authUser->image)
                    : null,
            ] : null
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Logout successful'
        ]);
    }
}
