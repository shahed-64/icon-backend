<?php

namespace App\Http\Controllers;

use App\Models\Result;
use App\Models\Student;
use App\Models\GroupSubjectMapping;
use App\Models\GradingSystem;
use App\Models\Examination;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ResultController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

public function index(Request $request)
{
    /*
    |--------------------------------------------------------------------------
    | Result Pagination Settings
    |--------------------------------------------------------------------------
    */

    $perPage = (int) $request->input('per_page', 10);

    // Minimum 1, Maximum 100
    $perPage = min(max($perPage, 1), 100);


    /*
    |--------------------------------------------------------------------------
    | Student Search
    |--------------------------------------------------------------------------
    |
    | Student ID অথবা Student Name দিয়ে search করা যাবে।
    |
    */

    $studentSearch = trim(
        $request->input('student_search', '')
    );


    /*
    |--------------------------------------------------------------------------
    | Results Query
    |--------------------------------------------------------------------------
    |
    | শুধুমাত্র Result server-side pagination হবে।
    |
    */

    $results = Result::with([
        'student.classInfo',
        'student.classGroup',
        'resultSubjects.subject'
    ])
        ->latest()
        ->paginate(
            $perPage,
            ['*'],
            'results_page'
        );


    /*
    |--------------------------------------------------------------------------
    | Students Query
    |--------------------------------------------------------------------------
    |
    | Result Insert-এর Student Select-এর জন্য
    | Student table থেকে সব student load হবে।
    |
    */

    $studentsQuery = Student::with([
        'classInfo.subjects',
        'classGroup.subjects'
    ]);


    /*
    |--------------------------------------------------------------------------
    | Student ID / Name Search
    |--------------------------------------------------------------------------
    */

    if ($studentSearch !== '') {

        $studentsQuery->where(function ($query) use ($studentSearch) {

            $query->where(
                'student_id',
                'like',
                "%{$studentSearch}%"
            )
            ->orWhere(
                'full_name',
                'like',
                "%{$studentSearch}%"
            );
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Get All Students
    |--------------------------------------------------------------------------
    |
    | এখানে কোনো pagination নেই।
    | Student table-এর সব matching student আসবে।
    |
    */

    $students = $studentsQuery
        ->orderBy('student_id', 'asc')
        ->get();


    /*
    |--------------------------------------------------------------------------
    | All Mapped Subject IDs
    |--------------------------------------------------------------------------
    */

    $allMappedSubjectIds = GroupSubjectMapping::pluck('subject_id')
        ->map(fn($id) => (int) $id)
        ->unique()
        ->values()
        ->toArray();


    /*
    |--------------------------------------------------------------------------
    | All Additional Subject IDs
    |--------------------------------------------------------------------------
    */

    $allAdditionalSubjectIds = DB::table('group_subjects')
        ->pluck('subject_id')
        ->map(fn($id) => (int) $id)
        ->unique()
        ->values()
        ->toArray();


    /*
    |--------------------------------------------------------------------------
    | Calculate GPA For Current Result Page
    |--------------------------------------------------------------------------
    |
    | এখানে শুধু current page-এর Result process হবে।
    |
    */

    $results->getCollection()->each(function ($result) {

        /*
        |--------------------------------------------------------------------------
        | Examination Information
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $result->exam_type
        )
            ->where(
                'examination_year',
                $result->exam_year
            )
            ->first();


        /*
        |--------------------------------------------------------------------------
        | Exam Mark
        |--------------------------------------------------------------------------
        */

        $examMark = $examination?->exam_mark;


        /*
        |--------------------------------------------------------------------------
        | GPA Variables
        |--------------------------------------------------------------------------
        */

        $totalPoints = 0;
        $subjectCount = 0;
        $hasFailed = false;


        /*
        |--------------------------------------------------------------------------
        | Calculate Subject GPA
        |--------------------------------------------------------------------------
        */

        foreach ($result->resultSubjects as $resultSubject) {

            $marks = $resultSubject->marks;


            /*
            |--------------------------------------------------------------------------
            | No Marks
            |--------------------------------------------------------------------------
            */

            if ($marks === null) {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | Full Mark
            |--------------------------------------------------------------------------
            |
            | Examination-এর exam_mark থাকলে সেটি ব্যবহার হবে।
            | না থাকলে Subject-এর full_mark ব্যবহার হবে।
            |
            */

            $fullMark = $examMark !== null
                ? (float) $examMark
                : (float) (
                    $resultSubject->subject?->full_mark ?? 100
                );


            /*
            |--------------------------------------------------------------------------
            | Invalid Full Mark
            |--------------------------------------------------------------------------
            */

            if ($fullMark <= 0) {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | Convert Marks To Percentage
            |--------------------------------------------------------------------------
            */

            $percentage = (
                ((float) $marks / $fullMark) * 100
            );


            /*
            |--------------------------------------------------------------------------
            | Find Grading System
            |--------------------------------------------------------------------------
            */

            $grading = GradingSystem::where(
                'min_percentage',
                '<=',
                $percentage
            )
                ->orderByDesc('min_percentage')
                ->first();


            /*
            |--------------------------------------------------------------------------
            | Grade Not Found
            |--------------------------------------------------------------------------
            */

            if (!$grading) {

                $point = 0.00;
                $hasFailed = true;

            } else {

                $point = (float) $grading->grade_point;


                /*
                |--------------------------------------------------------------------------
                | Fail Grade
                |--------------------------------------------------------------------------
                */

                if ($point == 0) {
                    $hasFailed = true;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Add Point
            |--------------------------------------------------------------------------
            */

            $totalPoints += $point;
            $subjectCount++;
        }


        /*
        |--------------------------------------------------------------------------
        | Calculate GPA
        |--------------------------------------------------------------------------
        */

        $gpa = 0.00;

        if (
            $subjectCount > 0
            && !$hasFailed
        ) {

            $gpa = $totalPoints / $subjectCount;

            $gpa = min(5.00, $gpa);
        }


        /*
        |--------------------------------------------------------------------------
        | Add Calculated GPA To Result
        |--------------------------------------------------------------------------
        */

        $result->setAttribute(
            'calculated_gpa',
            number_format($gpa, 2)
        );


        /*
        |--------------------------------------------------------------------------
        | Add Status To Result
        |--------------------------------------------------------------------------
        */

        $result->setAttribute(
            'calculated_status',
            $subjectCount > 0 && !$hasFailed
                ? 'Pass'
                : 'Fail'
        );


        /*
        |--------------------------------------------------------------------------
        | Add Exam Mark To Result
        |--------------------------------------------------------------------------
        */

        $result->setAttribute(
            'exam_mark',
            $examMark !== null
                ? (float) $examMark
                : null
        );
    });


    /*
    |--------------------------------------------------------------------------
    | Student Subject Mapping
    |--------------------------------------------------------------------------
    |
    | এখানে সব loaded student process হবে।
    |
    */

    $students->each(function ($student) use (
        $allMappedSubjectIds,
        $allAdditionalSubjectIds
    ) {

        /*
        |--------------------------------------------------------------------------
        | Student Group
        |--------------------------------------------------------------------------
        */

        $group = $student->classGroup;


        /*
        |--------------------------------------------------------------------------
        | Group Name
        |--------------------------------------------------------------------------
        */

        $student->setAttribute(
            'group_name',
            $group?->group_name
        );


        /*
        |--------------------------------------------------------------------------
        | Common Subjects
        |--------------------------------------------------------------------------
        */

        if ($student->classInfo) {

            $commonSubjects = $student->classInfo->subjects
                ->filter(function ($subject) use (
                    $allMappedSubjectIds,
                    $allAdditionalSubjectIds
                ) {

                    $subjectId = (int) $subject->id;


                    /*
                    |--------------------------------------------------------------------------
                    | Mapped Group Subject বাদ
                    |--------------------------------------------------------------------------
                    */

                    if (
                        in_array(
                            $subjectId,
                            $allMappedSubjectIds,
                            true
                        )
                    ) {
                        return false;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Additional Subject বাদ
                    |--------------------------------------------------------------------------
                    */

                    if (
                        in_array(
                            $subjectId,
                            $allAdditionalSubjectIds,
                            true
                        )
                    ) {
                        return false;
                    }


                    return true;
                })
                ->values();

        } else {

            $commonSubjects = collect();
        }


        /*
        |--------------------------------------------------------------------------
        | Set Common Subjects
        |--------------------------------------------------------------------------
        */

        if ($student->classInfo) {

            $student->classInfo->setRelation(
                'subjects',
                $commonSubjects
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Additional / Group Subjects
        |--------------------------------------------------------------------------
        */

        if ($group) {

            $groupSubjects = $group->subjects
                ->map(function ($subject) {

                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'code' => $subject->code,
                        'is_additional' => true,
                        'full_mark' => $subject->full_mark,
                    ];
                })
                ->values();

        } else {

            $groupSubjects = collect();
        }


        /*
        |--------------------------------------------------------------------------
        | Set Group Subjects
        |--------------------------------------------------------------------------
        */

        $student->setAttribute(
            'group_subjects',
            $groupSubjects
        );


        /*
        |--------------------------------------------------------------------------
        | Mapped Group Subjects
        |--------------------------------------------------------------------------
        */

        if ($group) {

            $mappedGroupSubjects =
                GroupSubjectMapping::with('subject')
                    ->where(
                        'class_group_id',
                        $group->id
                    )
                    ->get()
                    ->map(function ($mapping) {

                        return [
                            'id' =>
                                $mapping->subject?->id,

                            'name' =>
                                $mapping->subject?->name,

                            'code' =>
                                $mapping->subject?->code,

                            'is_additional' =>
                                false,

                            'class_group_id' =>
                                $mapping->class_group_id,

                            'full_mark' =>
                                $mapping->subject?->full_mark,
                        ];
                    })
                    ->filter(function ($subject) {

                        return !empty(
                            $subject['id']
                        );
                    })
                    ->values();

        } else {

            $mappedGroupSubjects = collect();
        }


        /*
        |--------------------------------------------------------------------------
        | Remove Additional Subjects
        | From Mapped Group Subjects
        |--------------------------------------------------------------------------
        */

        $currentAdditionalSubjectIds =
            $groupSubjects
                ->pluck('id')
                ->map(
                    fn($id) => (int) $id
                )
                ->unique()
                ->values()
                ->toArray();


        $mappedGroupSubjects =
            $mappedGroupSubjects
                ->filter(function ($subject) use (
                    $currentAdditionalSubjectIds
                ) {

                    return !in_array(
                        (int) $subject['id'],
                        $currentAdditionalSubjectIds,
                        true
                    );
                })
                ->values();


        /*
        |--------------------------------------------------------------------------
        | Set Mapped Group Subjects
        |--------------------------------------------------------------------------
        */

        $student->setAttribute(
            'mapped_group_subjects',
            $mappedGroupSubjects
        );
    });


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    return response()->json([

        'status' => true,


        /*
        |--------------------------------------------------------------------------
        | Current Page Results
        |--------------------------------------------------------------------------
        */

        'results' =>
            $results->items(),


        /*
        |--------------------------------------------------------------------------
        | ALL Students
        |--------------------------------------------------------------------------
        */

        'students' =>
            $students->values(),


        /*
        |--------------------------------------------------------------------------
        | RESULT PAGINATION
        |--------------------------------------------------------------------------
        */

        'results_pagination' => [

            'current_page' =>
                $results->currentPage(),

            'last_page' =>
                $results->lastPage(),

            'per_page' =>
                $results->perPage(),

            'total' =>
                $results->total(),

            'from' =>
                $results->firstItem(),

            'to' =>
                $results->lastItem(),
        ],


        /*
        |--------------------------------------------------------------------------
        | Total Students
        |--------------------------------------------------------------------------
        */

        'total_students' =>
            Student::count(),
    ]);
}
    /*
    |--------------------------------------------------------------------------
    | STORE RESULT
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([

            'student_id' => [
                'required',
                'exists:students,id',

                Rule::unique('results')->where(function ($query) use ($request) {

                    return $query
                        ->where(
                            'exam_year',
                            $request->exam_year
                        )
                        ->where(
                            'exam_type',
                            $request->exam_type
                        );
                }),
            ],

            'exam_year' => 'required|string|max:255',

            'exam_type' => 'required|string|max:255',

            'subjects' => 'required|array|min:1',

            'subjects.*.subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
            ],

            'subjects.*.marks' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999.99',
            ],

            'subjects.*.is_additional' => [
                'nullable',
                'boolean',
            ],

        ], [

            'student_id.unique' =>
                'This student already has a result entered for this exam type and year!',

            'subjects.required' =>
                'At least one subject is required.',

            'subjects.min' =>
                'At least one subject is required.',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Get Student
        |--------------------------------------------------------------------------
        */

        $student = Student::with([
            'classInfo.subjects',
            'classGroup.subjects'
        ])->findOrFail(
            $validated['student_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Get Examination Settings
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $validated['exam_type']
        )
            ->where(
                'examination_year',
                $validated['exam_year']
            )
            ->first();

        $examMark = $examination?->exam_mark;

        /*
        |--------------------------------------------------------------------------
        | Assigned Subject Validation
        |--------------------------------------------------------------------------
        */

        $classSubjectIds = $student->classInfo
            ? $student->classInfo->subjects
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray()
            : [];

        $group = $student->classGroup;

        $groupSubjectIds = $group
            ? $group->subjects
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray()
            : [];

        $mappedGroupSubjectIds = $group
            ? GroupSubjectMapping::where(
                'class_group_id',
                $group->id
            )
                ->pluck('subject_id')
                ->map(fn($id) => (int) $id)
                ->toArray()
            : [];

        $assignedSubjectIds = array_values(
            array_unique(
                array_merge(
                    $classSubjectIds,
                    $groupSubjectIds,
                    $mappedGroupSubjectIds
                )
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Validate Subjects And Maximum Marks
        |--------------------------------------------------------------------------
        */

        foreach ($validated['subjects'] as $subjectData) {

            $subjectId = (int) $subjectData['subject_id'];

            if (!in_array(
                $subjectId,
                $assignedSubjectIds,
                true
            )) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'One or more selected subjects are not assigned to this student.'
                ], 422);
            }

            $marks = $subjectData['marks'] ?? null;

            if ($marks === null || $marks === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Determine Maximum Allowed Marks
            |--------------------------------------------------------------------------
            */

            if ($examMark !== null) {

                $maximumMarks = (float) $examMark;

            } else {

                $subject = Subject::find($subjectId);

                $maximumMarks = (float) (
                    $subject?->full_mark ?? 100
                );
            }

            if ($maximumMarks <= 0) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Invalid maximum marks configured for one of the selected subjects.'
                ], 422);
            }

            if ((float) $marks > $maximumMarks) {

                $subject = Subject::find($subjectId);

                return response()->json([
                    'success' => false,
                    'message' =>
                        "Marks for '{$subject?->name}' cannot be greater than {$maximumMarks}."
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Store Result
        |--------------------------------------------------------------------------
        */

        $result = DB::transaction(function () use ($validated) {

            $result = Result::create([
                'student_id' =>
                    $validated['student_id'],

                'exam_year' =>
                    $validated['exam_year'],

                'exam_type' =>
                    $validated['exam_type'],
            ]);

            foreach ($validated['subjects'] as $subjectData) {

                $result->resultSubjects()->create([
                    'subject_id' =>
                        $subjectData['subject_id'],

                    'marks' =>
                        $subjectData['marks'] ?? null,
                ]);
            }

            return $result;
        });

        $result->load([
            'student.classInfo',
            'student.classGroup',
            'resultSubjects.subject'
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Result successfully stored!',

            'data' =>
                $result

        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW RESULT
    |--------------------------------------------------------------------------
    */

    public function show($id): JsonResponse
    {
        $result = Result::with([
            'student.classInfo',
            'student.classGroup.subjects',
            'resultSubjects.subject'
        ])->findOrFail($id);

        /*
        |--------------------------------------------------------------------------
        | Get Examination Settings
        |--------------------------------------------------------------------------
        */

        $examination = Examination::where(
            'examination_type',
            $result->exam_type
        )
            ->where(
                'examination_year',
                $result->exam_year
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Determine Effective Exam Mark
        |--------------------------------------------------------------------------
        */

        $examMark = $examination?->exam_mark;

        /*
        |--------------------------------------------------------------------------
        | Dynamic Grade Calculation
        |--------------------------------------------------------------------------
        */

        $getGradeAndPoint = function (
            $marks,
            $fullMark = 100
        ) {

            if ($marks === null) {
                return null;
            }

            $obtainedMarks = (float) $marks;

            $maximumMarks = (float) (
                $fullMark ?: 100
            );

            if ($maximumMarks <= 0) {
                return null;
            }

            $percentage =
                ($obtainedMarks / $maximumMarks) * 100;

            $grading = GradingSystem::where(
                'min_percentage',
                '<=',
                $percentage
            )
                ->orderByDesc('min_percentage')
                ->first();

            if (!$grading) {

                return [
                    'grade' => 'F',
                    'point' => 0.00
                ];
            }

            return [
                'grade' => $grading->grade,
                'point' => (float) $grading->grade_point
            ];
        };

        /*
        |--------------------------------------------------------------------------
        | Subjects
        |--------------------------------------------------------------------------
        */

        $subjects = [];

        $totalPoints = 0;
        $subjectCount = 0;
        $hasFailed = false;

        $student = $result->student;

        $group = $student?->classGroup;

        $groupSubjectIds = $group
            ? $group->subjects
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray()
            : [];

        foreach ($result->resultSubjects as $resultSubject) {

            $marks = $resultSubject->marks;

            if ($marks === null) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Determine Full Mark
            |--------------------------------------------------------------------------
            */

            $fullMark = $examMark !== null
                ? (float) $examMark
                : (
                    $resultSubject->subject?->full_mark
                    ?? 100
                );

            /*
            |--------------------------------------------------------------------------
            | Calculate Dynamic Grade
            |--------------------------------------------------------------------------
            */

            $gradePoint = $getGradeAndPoint(
                $marks,
                $fullMark
            );

            if ($gradePoint === null) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Check Additional Subject
            |--------------------------------------------------------------------------
            */

            $isAdditional = in_array(
                (int) $resultSubject->subject_id,
                $groupSubjectIds
            );

            /*
            |--------------------------------------------------------------------------
            | Subject Response
            |--------------------------------------------------------------------------
            */

            $subjects[] = [

                'id' =>
                    $resultSubject->subject_id,

                'subject_id' =>
                    $resultSubject->subject_id,

                'subject_name' =>
                    $resultSubject->subject->name
                    ?? 'Unknown Subject',

                'subject_code' =>
                    $resultSubject->subject->code
                    ?? null,

                'marks' =>
                    $marks,

                'full_mark' =>
                    $fullMark,

                'percentage' =>
                    round(
                        ((float) $marks / $fullMark) * 100,
                        2
                    ),

                'grade' =>
                    $gradePoint['grade'],

                'point' =>
                    number_format(
                        $gradePoint['point'],
                        2
                    ),

                'is_additional' =>
                    $isAdditional,
            ];

            /*
            |--------------------------------------------------------------------------
            | GPA Calculation
            |--------------------------------------------------------------------------
            */

            $totalPoints +=
                $gradePoint['point'];

            $subjectCount++;

            if (
                $gradePoint['point'] == 0
            ) {
                $hasFailed = true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Final GPA
        |--------------------------------------------------------------------------
        */

        $finalGpa = 0.00;

        if (
            $subjectCount > 0
            && !$hasFailed
        ) {

            $finalGpa =
                $totalPoints / $subjectCount;

            $finalGpa =
                min(5.00, $finalGpa);
        }

        /*
        |--------------------------------------------------------------------------
        | Group Name
        |--------------------------------------------------------------------------
        */

        $groupName =
            $group?->group_name;

        /*
        |--------------------------------------------------------------------------
        | Final Response
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'status' => true,

            'result' => [

                'student_name' =>
                    $student->full_name
                    ?? $student->name
                    ?? '[STUDENT NAME]',

                'father_name' =>
                    $student->fathers_name
                    ?? '[FATHER NAME]',

                'mother_name' =>
                    $student->mothers_name
                    ?? '[MOTHER NAME]',

                'institution_name' =>
                    $student->institution_name
                    ?? '[INSTITUTION NAME]',

                'roll' =>
                    $student->roll
                    ?? $student->student_id
                    ?? '[ROLL NO]',

                'reg_no' =>
                    $student->reg_no
                    ?? '[REGISTRATION NO]',

                'course_name' =>
                    $student->course_name
                    ?? null,

                'group_name' =>
                    $groupName,

                'class_name' =>
                    $student->classInfo->class_name
                    ?? 'N/A',

                'type' =>
                    $result->exam_type
                    ?? '[TYPE]',

                'year' =>
                    $result->exam_year,

                'exam_mark' =>
                    $examMark !== null
                        ? (float) $examMark
                        : null,

                'gpa' =>
                    number_format(
                        $finalGpa,
                        2
                    ),

                'gpa_without_additional' =>
                    number_format(
                        $finalGpa,
                        2
                    ),

                'publication_date' =>
                    $result->created_at
                        ? $result->created_at
                            ->format('d F Y')
                        : null,

                'subjects' =>
                    $subjects,

                'additional_subject' =>
                    collect($subjects)
                        ->where(
                            'is_additional',
                            true
                        )
                        ->values()
                        ->all(),
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    public function edit(Result $result)
    {
        //
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Result $result
    ) {
        //
    }

    /*
    |--------------------------------------------------------------------------
    | DESTROY
    |--------------------------------------------------------------------------
    */

    public function destroy(Result $result)
    {
        //
    }
}
