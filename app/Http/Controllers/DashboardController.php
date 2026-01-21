<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Models\Team;
use Carbon\Carbon;
use App\Models\LeaveType;
use App\Models\User;
class DashboardController extends Controller
{
    /**
     * Display the dashboard based on user role
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard($user);
        } elseif ($user->isManager()) {
            return $this->managerDashboard($user);
        } else {
            return $this->employeeDashboard($user);
        }
    }

    /**
     * Admin dashboard
     */
    private function adminDashboard($user): Response
    {
        $stats = [
            'total_teams' => Team::count(),
            'total_employees' => User::where('role', 'employee')->count(),
            'pending_requests' => LeaveRequest::where('status', 'pending')->count(),
            'approved_requests' => LeaveRequest::where('status', 'approved')->count(),
            'rejected_requests' => LeaveRequest::where('status', 'rejected')->count(),
        ];

        $recentRequests = LeaveRequest::with(['user.team', 'leaveType'])
            ->latest()
            ->limit(10)
            ->get();

        // Get admin's own leave requests
        $myLeaveRequests = LeaveRequest::with(['leaveType', 'user.team'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        // Get all active leave types that have balance
        $leaveTypes = LeaveType::where('is_active', true)
            ->where('has_balance', true)
            ->get();
        $year = now()->year;

        // Get existing balances
        $existingBalances = LeaveBalance::with('leaveType')
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        // Build balances array for all leave types
        $leaveBalances = $leaveTypes->map(function ($leaveType) use ($existingBalances) {
            $balance = $existingBalances->get($leaveType->id);

            if ($balance) {
                return [
                    'id' => $balance->id,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $balance->total_days,
                    'used_days' => $balance->used_days,
                    'pending_days' => $balance->pending_days,
                    'available_days' => $balance->available_days,
                ];
            } else {
                // No balance record exists, show default values
                $defaultTotalDays = $leaveType->max_days_per_year ?? 0;
                return [
                    'id' => null,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $defaultTotalDays,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'available_days' => $defaultTotalDays,
                ];
            }
        });

        return Inertia::render('Dashboard/Admin', [
            'stats' => $stats,
            'recentRequests' => $recentRequests,
            'myLeaveRequests' => $myLeaveRequests,
            'leaveBalances' => $leaveBalances,
        ]);
    }

    /**
     * Manager dashboard
     */
    private function managerDashboard($user): Response
    {
        $team = $user->team;
        $teamId = $team?->id;

        // Get team leave requests
        $leaveRequests = LeaveRequest::with(['user', 'leaveType'])
            ->whereHas('user', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            })
            ->latest()
            ->get();

        // Filter by status
        $pendingRequests = $leaveRequests->where('status', 'pending')->values();
        $approvedRequests = $leaveRequests->where('status', 'approved')->values();
        $rejectedRequests = $leaveRequests->where('status', 'rejected')->values();

        // Who is on leave today
        $onLeaveToday = LeaveRequest::with(['user', 'leaveType'])
            ->whereHas('user', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            })
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->get();

        // Who will be on leave next week
        $nextWeekStart = Carbon::now()->addWeek()->startOfWeek();
        $nextWeekEnd = Carbon::now()->addWeek()->endOfWeek();
        $onLeaveNextWeek = LeaveRequest::with(['user', 'leaveType'])
            ->whereHas('user', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            })
            ->where('status', 'approved')
            ->whereBetween('start_date', [$nextWeekStart, $nextWeekEnd])
            ->orWhereBetween('end_date', [$nextWeekStart, $nextWeekEnd])
            ->get();

        // Get all active leave types that have balance
        $leaveTypes = \App\Models\LeaveType::where('is_active', true)
            ->where('has_balance', true)
            ->get();
        $year = now()->year;

        // Get existing balances
        $existingBalances = LeaveBalance::with('leaveType')
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        // Build balances array for all leave types
        $leaveBalances = $leaveTypes->map(function ($leaveType) use ($existingBalances) {
            $balance = $existingBalances->get($leaveType->id);

            if ($balance) {
                return [
                    'id' => $balance->id,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $balance->total_days,
                    'used_days' => $balance->used_days,
                    'pending_days' => $balance->pending_days,
                    'available_days' => $balance->available_days,
                ];
            } else {
                // No balance record exists, show default values
                $defaultTotalDays = $leaveType->max_days_per_year ?? 0;
                return [
                    'id' => null,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $defaultTotalDays,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'available_days' => $defaultTotalDays,
                ];
            }
        });

        // Get manager's own leave requests
        $myLeaveRequests = LeaveRequest::with(['leaveType', 'user.team'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        // Calculate stats for manager dashboard
        $stats = [
            'total_employees' => \App\Models\User::where('team_id', $teamId)
                ->where('role', 'employee')
                ->count(),
            'pending_requests' => $pendingRequests->count(),
            'approved_requests' => $approvedRequests->count(),
            'rejected_requests' => $rejectedRequests->count(),
        ];

        return Inertia::render('Dashboard/Manager', [
            'team' => $team,
            'stats' => $stats,
            'pendingRequests' => $pendingRequests,
            'approvedRequests' => $approvedRequests,
            'rejectedRequests' => $rejectedRequests,
            'onLeaveToday' => $onLeaveToday,
            'onLeaveNextWeek' => $onLeaveNextWeek,
            'leaveBalances' => $leaveBalances,
            'myLeaveRequests' => $myLeaveRequests,
        ]);
    }

    /**
     * Employee dashboard
     */
    private function employeeDashboard($user): Response
    {
        // Get user's leave requests
        $leaveRequests = LeaveRequest::with(['leaveType', 'user.team'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        // Get all active leave types that have balance
        $leaveTypes = \App\Models\LeaveType::where('is_active', true)
            ->where('has_balance', true)
            ->get();
        $year = now()->year;

        // Get existing balances
        $existingBalances = LeaveBalance::with('leaveType')
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        // Build balances array for all leave types
        $leaveBalances = $leaveTypes->map(function ($leaveType) use ($existingBalances) {
            $balance = $existingBalances->get($leaveType->id);

            if ($balance) {
                return [
                    'id' => $balance->id,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $balance->total_days,
                    'used_days' => $balance->used_days,
                    'pending_days' => $balance->pending_days,
                    'available_days' => $balance->available_days,
                ];
            } else {
                // No balance record exists, show default values
                $defaultTotalDays = $leaveType->max_days_per_year ?? 0;
                return [
                    'id' => null,
                    'leave_type' => $leaveType->name,
                    'leave_type_id' => $leaveType->id,
                    'total_days' => $defaultTotalDays,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'available_days' => $defaultTotalDays,
                ];
            }
        });

        // Calculate stats for employee dashboard
        $stats = [
            'pending_requests' => $leaveRequests->where('status', 'pending')->count(),
            'approved_requests' => $leaveRequests->where('status', 'approved')->count(),
            'rejected_requests' => $leaveRequests->where('status', 'rejected')->count(),
        ];

        return Inertia::render('Dashboard/Employee', [
            'stats' => $stats,
            'leaveRequests' => $leaveRequests,
            'leaveBalances' => $leaveBalances,
        ]);
    }
}
