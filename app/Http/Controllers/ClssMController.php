<?php

namespace App\Http\Controllers;

use App\Models\ClssM;
use Illuminate\Http\Request;

class ClssMController extends Controller
{
    /**
     * Display a listing of the classes.
     */
    public function index()
    {
        $classes = ClssM::with('subjects')->latest()->get();

        return response()->json([
            'status' => true,
            'classes' => $classes
        ], 200);
    }

    /**
     * Show the form for creating a new class.
     */
    public function create()
    {
        // API-এর ক্ষেত্রে প্রয়োজন নেই
    }

    /**
     * Store a newly created class.
     */
    public function store(Request $request)
    {
        $request->validate([
            'class_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
        ]);

        // Class create
        $class = ClssM::create([
            'class_name' => $request->class_name,
        ]);

        // Selected subjects attach
        if ($request->has('subject_ids')) {
            $class->subjects()->sync($request->subject_ids);
        }

        // Subjects সহ fresh data
        $class->load('subjects');

        return response()->json([
            'status'  => true,
            'message' => 'Class Created Successfully',
            'class'   => $class
        ], 201);
    }

    /**
     * Display the specified class.
     */
    public function show(ClssM $class)
    {
        $class->load('subjects');

        return response()->json([
            'status' => true,
            'class'  => $class
        ], 200);
    }

    /**
     * Show the form for editing the specified class.
     */
    public function edit(ClssM $class)
    {
        // API-এর ক্ষেত্রে প্রয়োজন নেই
    }

    /**
     * Update the specified class.
     */
    public function update(Request $request, ClssM $class)
    {
        $request->validate([
            'class_name' => 'required|string|max:255',
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer|exists:subjects,id',
        ]);

        // Class update
        $class->update([
            'class_name' => $request->class_name
        ]);

        // Selected subjects থাকবে,
        // unselected subjects pivot table থেকে remove হবে।
        $class->subjects()->sync($request->subject_ids ?? []);

        // Subjects সহ fresh data
        $class->load('subjects');

        return response()->json([
            'status'  => true,
            'message' => 'Class Updated Successfully',
            'class'   => $class
        ], 200);
    }

    /**
     * Remove the specified class.
     */
    public function destroy(ClssM $class)
    {
        $class->delete();

        return response()->json([
            'status' => true,
            'message' => 'Class Deleted Successfully'
        ], 200);
    }
}
