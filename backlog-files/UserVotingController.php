<?php



namespace App\Http\Controllers\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vote;
use App\Models\BacklogProject;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class UserVotingController extends Controller
{
    public function index()
    {
        $projects = BacklogProject::where('status', '1')->get();
        return view('user.voting', compact('projects'));
    }

    public function getVoting(Request $request)
    {
        $id = Auth()->user()->id;
        
        // If it's a DataTable request, handle pagination
        if ($request->ajax()) {
            $records = Vote::with(['Projects'])->where('votes.user_id', $id)->withTrashed();
            $filters = json_decode($request->filters, true) ?? [];
            $records = $this->recordFilter($records, $filters);
            
            return DataTables::of($records)
                ->addColumn('vote_type_display', function($record) {
                    return $record->vote_type == 'up' ? '👍' : '👎';
                })
                ->addColumn('formatted_date', function($record) {
                    return $record->created_at ? $record->created_at->format('d M Y') : 'N/A';
                })
                ->addColumn('deleted_status', function($record) {
                    return $record->deleted_at ? 'Deleted By Admin' : 'N/A';
                })
                ->addColumn('row_class', function($record) {
                    return $record->deleted_at ? 'text-decoration-line-through text-danger' : '';
                })
                ->editColumn('comment', function($record) {
                    return $record->comment ?? 'Null';
                })
                ->editColumn('projects.id', function($record) {
                    return $record->projects ? $record->projects->id : 'N/A';
                })
                ->rawColumns(['vote_type_display'])
                ->with([
                    'total_votes' => Vote::where('user_id', $id)->withTrashed()->count(),
                    'deleted_votes' => Vote::onlyTrashed()->where('user_id', $id)->count(),
                    'up_votes' => Vote::where('vote_type', 'up')->where('user_id', $id)->count(),
                    'down_votes' => Vote::where('vote_type', 'down')->where('user_id', $id)->count(),
                ])
                ->make(true);
        }

        // Keep original logic for non-AJAX requests (backward compatibility)
        $records = Vote::with(['Projects'])->where('votes.user_id', $id)->withTrashed();
        $filters = json_decode($request->filters, true) ?? [];
        $records = $this->recordFilter($records, $filters);
        $data['records'] = $records->orderBy('created_at', 'DESC')->get();
        $data['total_votes'] = Vote::where('user_id', $id)->withTrashed()->count();
        $data['deleted_votes'] = Vote::onlyTrashed()->where('user_id', $id)->count();
        $data['up_votes'] = Vote::where('vote_type', 'up')->where('user_id', $id)->count();
        $data['down_votes'] = Vote::where('vote_type', 'down')->where('user_id', $id)->count();
        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function recordFilter($records, $filters)
    {
        $hasFilters = false; 
        
        // Apply category filters
        if (!empty($filters['projectFilter'])) {
            $records->where('project_id', $filters['projectFilter']);
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
                $query->where('votes.comment', 'LIKE', "%$search%");
                $query->orWhere('votes.project_id', 'LIKE', "%$search%");
                $query->orWhere('votes.id', 'LIKE', "%$search%");
            });
            $hasFilters = true;
        }
        
        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            $hasFilters = true;
        }
        
        // Apply default ordering if no filters were applied
        if (!$hasFilters) {
            $records->orderBy('created_at', 'DESC');
        }
        
        return $records;
    }
}