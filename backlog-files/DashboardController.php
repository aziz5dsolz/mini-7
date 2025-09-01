<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use App\Models\Backlogs;
use App\Models\BacklogProject;
use App\Models\CoinDistribution;
use App\Models\PaymentSetting;
use App\Models\HistoryLog;
use App\Models\Notification;
use Exception;

class DashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        
        $totalPendingBacklogs = Backlogs::where('status_id', 1)->get();

        $pendingBackLogs = Backlogs::with('user')
            ->where('status_id', 1)
            ->limit(10)
            ->get();

        $totalPendingProjects = BacklogProject::where('status', '0')->get();

        $pendingProjects = BacklogProject::where('status', '0')
            ->limit(10)
            ->get();

        $totalUsers = DB::table('users')->where('role', '2')->count();

        // Get filter parameter
        $userFilter = $request->get('user_filter', 'all'); 

        // Get users based on filter
        $allUsersQuery = User::whereIn('role', ['1', '2'])
            ->select('id', 'first_name', 'last_name', 'image', 'email', 'status', 'created_at');

        if ($userFilter == 'with_field_of_study') {
            $allUsersQuery->whereHas('additionalInfo', function ($query) {
                $query->whereNotNull('field_of_study')
                    ->where('field_of_study', '!=', '');
            });
        }

        $allUsers = $allUsersQuery->orderByRaw('status DESC, created_at DESC')
            ->limit(50)
            ->get();

        // Get users with field of study count for the filter buttons
        $usersWithFieldOfStudy = User::whereIn('role', ['1', '2'])
            ->whereHas('additionalInfo', function ($query) {
                $query->whereNotNull('field_of_study')
                    ->where('field_of_study', '!=', '');
            })->count();

        
        $totalDoingCount = Backlogs::whereNotIn('backlogs.status_id', [3, 5])->get();

        // Then get the actual records with limit(10)
        $doing = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->whereNotIn('backlogs.status_id', [3, 5])
            ->limit(10)
            ->get();


        // Manually add counts with correct property names
        $doing = $doing->map(function ($backlog) use ($totalUsers) {
            // Get submission count (use submissions_count to match Blade template)
            $backlog->submissions_count = BacklogProject::where('backlog_id', $backlog->id)->count();

            // Get voting turnout (distinct users who voted)
            $backlog->voting_turn_out = DB::table('votes')
                ->join('backlog_projects', 'votes.project_id', '=', 'backlog_projects.id')
                ->where('backlog_projects.backlog_id', $backlog->id)
                ->distinct('votes.user_id')
                ->count('votes.user_id');

            $backlog->total_users = $totalUsers;
            $backlog->voting_percentage = $totalUsers > 0
                ? ($backlog->voting_turn_out / $totalUsers) * 100
                : 0;

            return $backlog;
        });

        // Done with submission count and voting turnout
        $totalDoneCount = Backlogs::where('backlogs.status_id', '=', 5)->get();

        $done = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->where('backlogs.status_id', '=', 5)
            ->limit(10)
            ->get();


        $done = $done->map(function ($backlog) use ($totalUsers) {
            // Get submission count (use submissions_count to match Blade template)
            $backlog->submissions_count = BacklogProject::where('backlog_id', $backlog->id)->count();

            // Get voting turnout (distinct users who voted)
            $backlog->voting_turn_out = DB::table('votes')
                ->join('backlog_projects', 'votes.project_id', '=', 'backlog_projects.id')
                ->where('backlog_projects.backlog_id', $backlog->id)
                ->distinct('votes.user_id')
                ->count('votes.user_id');

            $backlog->total_users = $totalUsers;
            $backlog->voting_percentage = $totalUsers > 0
                ? ($backlog->voting_turn_out / $totalUsers) * 100
                : 0;

            return $backlog;
        });


        // Approved Projects (status 1 = approved)
        $totalApprovedProjects = BacklogProject::where('status', '1')->get();

        $approvedProjects = BacklogProject::with(['User', 'Backlog'])
            ->where('status', '1')
            ->withCount(['votes as total_votes'])
            ->limit(10)
            ->get();


        // Completed Projects (status 3 = completed)
        $totalCompletedProjects = BacklogProject::where('status', '3')->get();

        $completedProjects = BacklogProject::with(['User', 'Backlog'])
            ->where('status', '3')
            ->withCount(['votes as total_votes'])
            ->limit(10)
            ->get();


        $totalProjects = BacklogProject::with(['User', 'Backlog'])
            ->whereIn('status', ['0', '1', '3'])
            ->withCount([
                'votes as total_votes'
            ])->get();

        $total_coins = PaymentSetting::find(1)->total_yearly_tokens;

        $distributed_to_solver = CoinDistribution::where('status', 'completed')
            ->where('reference_type', 'App\Models\BacklogProject')
            ->sum(DB::raw('CAST(amount AS DECIMAL(18,8))'));

        $distributed_to_reviewer = CoinDistribution::where('status', 'completed')
            ->where('reference_type', 'App\Models\Vote')
            ->sum(DB::raw('CAST(amount AS DECIMAL(18,8))'));

        $coinDistribution = [
            'total_coins' => $total_coins,
            'distributed_to_solver' => $distributed_to_solver,
            'distributed_to_reviewer' => $distributed_to_reviewer,
        ];

        $latestActions = HistoryLog::with('user')->orderBy('created_at', 'asc')->get();

        return view('user.dashboard', compact(
            'pendingBackLogs',
            'pendingProjects',
            'doing',
            'done',
            'approvedProjects',        
            'completedProjects',       
            'coinDistribution',
            'latestActions',
            'totalUsers',
            'allUsers',
            'usersWithFieldOfStudy',
            'totalProjects',
            'totalDoingCount',
            'totalDoneCount',
            'totalApprovedProjects',
            'totalCompletedProjects',
            'totalPendingBacklogs',
            'totalPendingProjects'
        ));
    }
}
