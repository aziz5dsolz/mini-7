<?php





namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CoinDistribution;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class UserCoinDistributionController extends Controller
{
    public function index()
    {
        $user_id = Auth::user()->id;

        $total_earned = CoinDistribution::where('user_id',$user_id)
        ->where('status','completed')
        ->sum('amount');
        
        $pending_amount = CoinDistribution::where('user_id',$user_id)
        ->where('status','pending')
        ->sum('amount');

        $voter_pending_amount = CoinDistribution::where('user_id',$user_id)
        ->where('status','pending')
        ->where('reference_type','App\Models\Vote')
        ->sum('amount');

        $developer_pending_amount = CoinDistribution::where('user_id',$user_id)
        ->where('status','pending')
        ->where('reference_type','App\Models\BacklogProject')
        ->sum('amount');

        $data['total_earned'] = $total_earned;
        $data['pending_amount'] = $pending_amount;
        $data['voter_pending_amount'] = $voter_pending_amount;
        $data['developer_pending_amount'] = $developer_pending_amount;
        
        return view('user.coin_distribution', compact('data'));
    }

    public function getCoinDistribution(Request $request)
    {
        $id = Auth::user()->id;
        $coinDistributionents = CoinDistribution::with('user', 'reference')->where('user_id', $id);

        $filters = json_decode($request->filters, true) ?? [];
        $coinDistributionents = $this->recordFilter($coinDistributionents, $filters);

        return DataTables::of($coinDistributionents)
            ->addColumn('type', function ($record) {
                return $record->reference_type === "App\\Models\\BacklogProject" ? 'Developer' : 'Voter';
            })
            ->addColumn('project_id', function ($record) {
                return $record->reference_type === "App\\Models\\BacklogProject" 
                    ? $record->reference->id 
                    : $record->reference->project_id;
            })
            ->addColumn('status_badge', function ($record) {
                if ($record->status == 'pending') {
                    return '<span class="badge bg-secondary">Pending</span>';
                } elseif ($record->status == 'completed') {
                    return '<span class="badge bg-success">Completed</span>';
                } else {
                    return '<span class="badge bg-danger">Failed</span>';
                }
            })
            ->addColumn('formatted_date', function ($record) {
                return $record->created_at ? $record->created_at->format('d M Y') : 'N/A';
            })
            ->rawColumns(['status_badge'])
            ->make(true);
    }

    public function recordFilter($records, $filters)
    {
        $hasFilters = false; // Track if any filters are applied

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            $hasFilters = true;
        }

        // Apply status filters
        if (!empty($filters['advanceStatusFilter'])) {
            $records->where('status', $filters['advanceStatusFilter']);
            $hasFilters = true;
        }
        
        // Apply category filters
        if (!empty($filters['advanceTypeFilter'])) {
            $records->where('reference_type', $filters['advanceTypeFilter']);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'] . ' 00:00:00';
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }
        
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('coin_distributions.amount', 'LIKE', "%$search%")
                    ->orWhere('coin_distributions.reference_id', 'LIKE', "%$search%")
                    ->orWhere('coin_distributions.id', 'LIKE', "%$search%")
                    ->orWhere('coin_distributions.status', 'LIKE', "%$search%")
                    ->orWhereHas('User', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    });
                
                // Add manual label matching
                if (stripos('voter', $search) !== false) {
                    $query->orWhere('coin_distributions.reference_type', 'App\\Models\\Vote');
                }

                if (stripos('developer', $search) !== false) {
                    $query->orWhere('coin_distributions.reference_type', 'App\\Models\\BacklogProject');
                }
            });
            $hasFilters = true;
        }

        // Apply default ordering if no filters were applied
        if (!$hasFilters) {
            $records->orderBy('created_at', 'DESC');
        }

        return $records;
    }
}