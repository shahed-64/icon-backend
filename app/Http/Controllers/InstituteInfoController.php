<?php

namespace App\Http\Controllers;

use App\Models\InstituteInfo;
use Illuminate\Http\Request;

class InstituteInfoController extends Controller
{
    /**
     * Display the institute information.
     */
public function index()
{
    $start = microtime(true);

    $institute = InstituteInfo::first();

    $dbTime = microtime(true) - $start;

    $start = microtime(true);

    $data = $institute
        ? $this->formatInstitute($institute)
        : null;

    $formatTime = microtime(true) - $start;

    \Log::info('Institute API Timing', [
        'db_time' => $dbTime,
        'format_time' => $formatTime,
        'total_time' => $dbTime + $formatTime,
    ]);

    return response()->json([
        'success' => true,
        'data' => $data,
    ]);
}

    /**
     * Store institute information.
     *
     * Only one institute can exist.
     */
    public function store(Request $request)
    {
        // Check if institute already exists
        if (InstituteInfo::exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Institute information already exists. Delete the existing institute before creating a new one.',
            ], 409);
        }


        $validated = $request->validate([
            'institute_name' => 'required|string|max:255',
            'established_year' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);


        // Upload logo
        if ($request->hasFile('logo')) {

            $validated['logo'] = $this->uploadImage(
                $request->file('logo'),
                'institute'
            );
        }


        $institute = InstituteInfo::create($validated);


        return response()->json([
            'success' => true,
            'message' => 'Institute information created successfully.',
            'data' => $this->formatInstitute($institute),
        ], 201);
    }


    /**
     * Display the specified institute.
     */
    public function show(InstituteInfo $instituteInfo)
    {
        return response()->json([
            'success' => true,
            'data' => $this->formatInstitute($instituteInfo),
        ]);
    }


    /**
     * Update institute information.
     */
    public function update(Request $request, InstituteInfo $instituteInfo)
    {
        $validated = $request->validate([
            'institute_name' => 'required|string|max:255',
            'established_year' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);


        // Upload new logo
        // Old logo will automatically be deleted
        if ($request->hasFile('logo')) {

            $validated['logo'] = $this->uploadImage(
                $request->file('logo'),
                'institute',
                $instituteInfo->logo
            );
        }


        $instituteInfo->update($validated);


        // Refresh updated data
        $instituteInfo->refresh();


        return response()->json([
            'success' => true,
            'message' => 'Institute information updated successfully.',
            'data' => $this->formatInstitute($instituteInfo),
        ]);
    }


    /**
     * Delete institute information.
     */
    public function destroy(InstituteInfo $instituteInfo)
    {
        // Delete institute logo
        if ($instituteInfo->logo) {

            $this->deleteImage(
                $instituteInfo->logo
            );
        }


        $instituteInfo->delete();


        return response()->json([
            'success' => true,
            'message' => 'Institute information deleted successfully.',
        ]);
    }


    /**
     * Format institute data for API response.
     */
    private function formatInstitute($institute)
    {
        if (!$institute) {
            return null;
        }


        $data = $institute->toArray();


        if ($institute->logo) {

            $data['logo'] = asset(
                'storage/' . $institute->logo
            );
        }


        return $data;
    }
}
