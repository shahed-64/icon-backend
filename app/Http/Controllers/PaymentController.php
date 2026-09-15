<?php

namespace App\Http\Controllers;

use App\Models\OtherPayment;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /* =========================
        ALL PAYMENTS LIST
    ========================= */
    public function index(Request $request)
    {
        $totalPaidAmount = Payment::sum('paid_amount');
        $totalDueAmount = Payment::sum('due_amount');
        $totalStudents = Student::count();

        // যাদের payment আছে কিন্তু due > 0
        $dueStudents = Payment::where('due_amount', '>', 0)
            ->distinct('student_id')
            ->count('student_id');

        /*
        |--------------------------------------------------------------------------
        | Today's Collection
        |--------------------------------------------------------------------------
        */
        $todayCollection = Payment::whereDate('payment_date', today())
            ->sum('paid_amount');

        $todayOtherCollection = OtherPayment::whereDate('payment_date', today())
            ->sum('total_amount');

        $todayCollection += $todayOtherCollection;

        /*
        |--------------------------------------------------------------------------
        | This Month Collection (Paid + Admission + Exam + Other Payment)
        |--------------------------------------------------------------------------
        */
        $thisMonthCollectionQuery = Payment::whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear('payment_date', now()->year)
            ->select(
                DB::raw('SUM(paid_amount + admission_fee + exam_fee) as total')
            )
            ->value('total');

        $thisMonthCollection = (float) $thisMonthCollectionQuery;

        $otherPaymentCollection = OtherPayment::whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear('payment_date', now()->year)
            ->sum('total_amount');

        $thisMonthCollection += $otherPaymentCollection;

        /*
        |--------------------------------------------------------------------------
        | This Month Due
        |--------------------------------------------------------------------------
        */
        $thisMonthDue = Payment::whereMonth(
                'payment_date',
                now()->month
            )
            ->whereYear('payment_date', now()->year)
            ->sum('due_amount');

        /*
        |--------------------------------------------------------------------------
        | Monthly Payment Chart
        |--------------------------------------------------------------------------
        */
        $monthlyPayments = Payment::select(
                DB::raw('MONTH(payment_date) as month'),
                DB::raw('SUM(paid_amount) as total')
            )
            ->whereYear('payment_date', date('Y'))
            ->groupBy(DB::raw('MONTH(payment_date)'))
            ->orderBy('month')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Recent Payments Last 7 Days
        |--------------------------------------------------------------------------
        */
        $recentPayments = Payment::with('student')
            ->whereDate(
                'payment_date',
                '>=',
                Carbon::today()->subDays(6)
            )
            ->orderBy('payment_date', 'desc')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Running Month Unpaid Students
        |--------------------------------------------------------------------------
        */
        $currentMonth = now()->format('F');

        $runningMonthUnpaidStudents = Student::whereNotExists(
            function ($query) use ($currentMonth) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_id',
                        'students.id'
                    )
                    ->where(
                        'payments.month',
                        $currentMonth
                    );
            }
        )->count();

        /*
        |--------------------------------------------------------------------------
        | Total Unpaid Students
        |--------------------------------------------------------------------------
        */
        $totalUnpaidStudents = Student::whereNotExists(
            function ($query) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn(
                        'payments.student_id',
                        'students.id'
                    );
            }
        )->count();

        /*
        |--------------------------------------------------------------------------
        | Payments List
        |--------------------------------------------------------------------------
        | Pagination added here.
        | Only 20 records will be loaded per page.
        |--------------------------------------------------------------------------
        */
        $perPage = 20;

        $payments = Payment::with([
            'student' => function ($q) {
                $q->select(
                    'id',
                    'full_name',
                    'student_id',
                    'class_id'
                );
            }
        ])
            ->latest()
            ->paginate($perPage);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'status' => true,
            'message' => 'Payments fetched successfully',

            // Summary
            'total_paid_amount' => $totalPaidAmount,
            'total_due_amount' => $totalDueAmount,
            'total_students' => $totalStudents,
            'due_students' => $dueStudents,
            'today_collection' => $todayCollection,

            // Dashboard
            'this_month_collection' => $thisMonthCollection,
            'this_month_due' => $thisMonthDue,

            // Student Summary
            'running_month_unpaid_students' => $runningMonthUnpaidStudents,
            'total_unpaid_students' => $totalUnpaidStudents,

            // Chart
            'monthly_payments' => $monthlyPayments,

            // Recent Payments
            'recent_payments' => $recentPayments,

            // Paginated Payments
            'payments' => $payments,
        ]);
    }

    /* =========================
        STORE PAYMENT
    ========================= */
    public function store(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'amount' => 'required|numeric',
            'paid_amount' => 'required|numeric',
            'payment_method' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'month' => 'required|string',
            'admission_fee' => 'nullable|numeric',
            'exam_fee' => 'nullable|numeric',
        ]);

        $student = Student::findOrFail($request->student_id);

        if (empty($student->monthly_fee)) {
            $student->monthly_fee = $request->amount;
            $student->save();
        }

        $exists = Payment::where('student_id', $request->student_id)
            ->where('month', $request->month)
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'Payment for this month already exists.'
            ], 422);
        }

        $amount = $student->monthly_fee;
        $due = $amount - $request->paid_amount;

        $payment = Payment::create([
            'student_id' => $request->student_id,
            'amount' => $amount,
            'paid_amount' => $request->paid_amount,
            'due_amount' => $due,
            'payment_method' => $request->payment_method,
            'payment_date' => now()->toDateString(),
            'month' => $request->month,
            'admission_fee' => $request->admission_fee,
            'exam_fee' => $request->exam_fee,
            'status' => $due <= 0 ? 'paid' : 'due',
        ]);

        $payment->load('student');

        return response()->json([
            'status' => true,
            'message' => 'Payment created successfully',
            'payment' => $payment
        ]);
    }

    /* =========================
        SINGLE PAYMENT (MOST IMPORTANT)
    ========================= */
    public function show($id)
    {
        $payment = Payment::with([
            'student.classInfo',
            'student.section'
        ])
            ->where('id', $id)
            ->first();

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'payment' => $payment
        ]);
    }

    /* =========================
        STUDENT PAYMENT HISTORY
    ========================= */
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

    /*
    |--------------------------------------------------------------------------
    | SINGLE STUDENT PAYMENT REPORT
    |--------------------------------------------------------------------------
    */
    public function studentPaymentReport()
    {
        $students = Student::with('payments')->get();

        $report = $students->map(function ($student) {

            $totalPaid = $student->payments->sum('paid_amount');
            $totalDue = $student->payments->sum('due_amount');

            $startDate = Carbon::parse(
                $student->admission_date
            )->startOfMonth();

            $endDate = Carbon::now()->startOfMonth();

            $paidMonths = $student->payments
                ->pluck('month')
                ->toArray();

            $unpaidMonths = 0;

            while ($startDate <= $endDate) {

                $monthName = $startDate->format('F');

                if (!in_array($monthName, $paidMonths)) {
                    $unpaidMonths++;
                }

                $startDate->addMonth();
            }

            $unpaidAmount = $unpaidMonths * $student->monthly_fee;

            $totalOutstanding = $totalDue + $unpaidAmount;

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'full_name' => $student->full_name,
                'phone' => $student->phone,
                'batch_name' => $student->batch_name ?? null,
                'monthly_fee' => $student->monthly_fee,
                'status' => $student->status ?? null,
                'payments' => $student->payments,
                'total_paid' => $totalPaid,
                'total_due' => $totalDue,
                'unpaid_months' => $unpaidMonths,
                'unpaid_amount' => $unpaidAmount,
                'total_outstanding' => $totalOutstanding,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Student payment report fetched successfully',
            'students' => $report
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PAYMENT
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, $id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment record not found'
            ], 404);
        }

        $request->validate([
            'paid_amount' => 'required|numeric',
            'payment_method' => 'required|string',
            'month' => 'required|string',
            'admission_fee' => 'nullable|numeric',
            'exam_fee' => 'nullable|numeric',
        ]);

        $totalAmount = $payment->amount;
        $paidAmount = $request->paid_amount;
        $dueAmount = $totalAmount - $paidAmount;

        $payment->update([
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_method' => $request->payment_method,
            'month' => $request->month,
            'admission_fee' => $request->admission_fee
                ?? $payment->admission_fee,
            'exam_fee' => $request->exam_fee
                ?? $payment->exam_fee,
            'status' => $dueAmount <= 0 ? 'paid' : 'due',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Payment updated successfully',
            'payment' => $payment->load('student')
        ]);
    }

    /* =========================
        DELETE PAYMENT
    ========================= */
    public function destroy($id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'status' => false,
                'message' => 'Payment record not found'
            ], 404);
        }

        $payment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Payment deleted successfully'
        ]);
    }
}
