<?php


namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BacklogCategory;
use App\Models\BacklogProject;
use App\Models\BacklogStatus;
use App\Models\Backlogs;
use Illuminate\Http\Request;
use App\Helpers\LogConstants;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;
use App\Services\GitHubService;
use Exception;
use App\Services\FileUploadService;

class UserBacklogController extends Controller
{

    protected $githubService;
    protected $fileUploadService;

    public function __construct(GitHubService $githubService, FileUploadService $fileUploadService)
    {
        $this->githubService = $githubService;
        $this->fileUploadService = $fileUploadService;
    }

    public function index()
    {
        $statuses = BacklogStatus::all();
        $categories = BacklogCategory::all();
        return view('user.backlogs', compact('statuses', 'categories'));
    }


    public function saveBacklogs(Request $request)
    {
        
        $currentUser = Auth()->user();
        $id = $currentUser->id;

        
        Log::info('saveBacklogs Debug Info', [
            'authenticated_user_id' => $id,
            'authenticated_user_email' => $currentUser->email ?? 'N/A',
            'authenticated_user_name' => ($currentUser->first_name ?? 'N/A') . ' ' . ($currentUser->last_name ?? 'N/A'),
            'request_data' => $request->except(['file', '_token']),
            'session_user_id' => session('user_id', 'not_set'),
            'auth_check' => Auth::check(),
            'auth_id' => Auth::id(),
        ]);

        $validatedData = $request->validate([
            'title' => 'required|max:200',
            'backlog_category_id' => 'required',
            'description' => 'required|max:255',
            'file' => 'required',
        ]);

        if ($request->backlog_id == '') {
            $record = new Backlogs;
        } else {
            $record = Backlogs::find($request->backlog_id);
        }

        $record->title = $request->title;
        $record->backlog_category_id = $request->backlog_category_id;
        $record->description = $request->description;
        $record->status_id = 1; // Pending status
        $record->created_by = Auth::user()->id;

        
        Log::info('About to save backlog', [
            'created_by' => $record->created_by,
            'title' => $record->title,
            'user_id_used' => $id
        ]);

        
        if ($request->hasFile('file')) {
            try {
                
                $uploadResults = $this->fileUploadService->autoDetectAndHandleUploads($request);

                Log::info('File upload processing completed', [
                    'upload_type' => $uploadResults['upload_type'],
                    'total_files' => $uploadResults['total_files'],
                    'files_processed' => count($uploadResults['local_files'])
                ]);

                
                if (!empty($uploadResults['local_files'])) {
                    $record->file = $uploadResults['local_files'][0];
                }
            } catch (Exception $e) {
                Log::error('Error processing file uploads', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'backlog_title' => $request->title
                ]);

                return response()->json([
                    'status' => 422,
                    'message' => 'Error processing uploaded files: ' . $e->getMessage()
                ]);
            }
        }

        $record->save();

        
        Log::info('Backlog saved', [
            'saved_record_id' => $record->id,
            'saved_created_by' => $record->created_by,
            'fresh_record_created_by' => Backlogs::find($record->id)->created_by
        ]);

        logAction(
            LogConstants::CREATED,
            LogConstants::BACKLOG,
            $record->id,
            "Backlog id '{$record->id}' was created."
        );

        Notification(
            'backlog',
            $record->id,
            'A new backlog has been created. Please review and approve the backlog.',
            'admin'
        );

        return response()->json([
            'status' => 200,
            'message' => 'Record Store Successfully'
        ]);
    }


    public function addCollaborator(Request $request)
    {
        try {
            $backlogId = $request->backlog_id;
            $userId = Auth()->user()->id;
            
            $backlog = Backlogs::find($backlogId);

            if (!$backlog) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Backlog not found'
                ]);
            }

            if (!$backlog->github_repo_name || !$backlog->github_repo_url) {
                return response()->json([
                    'status' => 400,
                    'message' => 'No GitHub repository found for this backlog'
                ]);
            }

            $currentUser = Auth()->user();
            if (!$currentUser->github_username) {
                return response()->json([
                    'status' => 400,
                    'message' => 'GitHub username not found in your profile. Please update your profile with your GitHub username.'
                ]);
            }

            // Add user as collaborator to the GitHub repository
            $result = $this->githubService->addCollaborator(
                $backlog->github_repo_name,
                $currentUser->github_username,
                'pull' 
            );

            if ($result['success']) {
                // Log the action
                logAction(
                    LogConstants::UPDATED,
                    LogConstants::BACKLOG,
                    $backlog->id,
                    "User '{$currentUser->github_username}' was added as collaborator to repository '{$backlog->github_repo_name}'"
                );

                return response()->json([
                    'status' => 200,
                    'message' => $result['message'],
                    'already_collaborator' => $result['already_collaborator'] ?? false,
                    'github_repo_url' => $backlog->github_repo_url
                ]);
            } else {
                Log::error("Failed to add collaborator to GitHub repository", [
                    'backlog_id' => $backlogId,
                    'github_username' => $currentUser->github_username,
                    'repo_name' => $backlog->github_repo_name,
                    'error' => $result['error']
                ]);

                return response()->json([
                    'status' => 500,
                    'message' => $result['error'] ?? 'Failed to add as collaborator'
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in addCollaborator method", [
                'backlog_id' => $request->backlog_id ?? null,
                'user_id' => Auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while adding you as collaborator'
            ]);
        }
    }

    public function getBacklogs(Request $request)
    {
        $id = Auth()->user()->id;
        $totalUsers = DB::table('users')->where('role', '2')->count();
        
        $filters = json_decode($request->filters, true) ?? [];
        $showDeletedBacklogs = $filters['showDeletedBacklogs'] ?? false;
        
        if ($request->ajax() && $request->has('draw')) {
            if ($showDeletedBacklogs) {
                
                $query = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
                    ->where('backlogs.created_by', $id)
                    ->where('backlogs.status_id', 3)
                    ->withCount([
                        'Projects as submission' => function ($query) {
                            $query->whereIn('status', ['1', '3']);
                        }
                    ])
                    ->withCount([
                        'Projects as voting_turn_out' => function ($query) {
                            $query->join('votes', 'backlog_projects.id', '=', 'votes.project_id')
                                ->selectRaw('COUNT(DISTINCT votes.user_id)');
                        }
                    ]);
            } else {
                
                $query = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
                    ->where(function ($mainQuery) use ($id) {
                        $mainQuery->where(function ($doingQuery) use ($id) {
                            $doingQuery->where('backlogs.created_by', $id)
                                ->whereNotIn('backlogs.status_id', [3, 5])
                                ->orWhere('backlogs.status_id', 2);
                        })->orWhere(function ($doneQuery) use ($id) {
                            $doneQuery->where('backlogs.status_id', 5)
                                ->orWhere(function ($userDoneQuery) use ($id) {
                                    $userDoneQuery->where('created_by', $id)
                                        ->where('status_id', 5);
                                });
                        });
                    })
                    ->withCount([
                        'Projects as submission' => function ($query) {
                            $query->whereIn('status', ['1', '3']);
                        }
                    ])
                    ->withCount([
                        'Projects as voting_turn_out' => function ($query) {
                            $query->join('votes', 'backlog_projects.id', '=', 'votes.project_id')
                                ->selectRaw('COUNT(DISTINCT votes.user_id)');
                        }
                    ]);
            }

            // Apply filters
            $query = $this->recordFilter($query, $filters, $id);

            return DataTables::of($query)
                ->addColumn('status_badge', function ($row) {
                    $spanBg = '';
                    switch ($row->status_id) {
                        case 1:
                            $spanBg = 'bg-secondary';
                            break;
                        case 2:
                            $spanBg = 'bg-primary';
                            break;
                        case 3:
                            $spanBg = 'bg-danger';
                            break;
                        case 4:
                            $spanBg = 'bg-light';
                            break;
                        case 5:
                            $spanBg = 'bg-success';
                            break;
                    }
                    return '<span class="badge ' . $spanBg . '">' . $row->backlog_status->name . '</span>';
                })
                ->addColumn('created_by_name', function ($row) {
                    return $row->user->first_name . ' ' . $row->user->last_name;
                })
                ->addColumn('submission_link', function ($row) use ($id) {
                    $submissionColor = '';
                    switch ($row->submission) {
                        case '0':
                            $submissionColor = 'black';
                            break;
                        case '1':
                            $submissionColor = 'orange';
                            break;
                        case '2':
                            $submissionColor = 'yellow';
                            break;
                        default:
                            $submissionColor = 'green';
                            break;
                    }
                    return '<a href="/projects/' . $row->id . '" style="text-decoration: none; color:' . $submissionColor . '">' . $row->submission . '</a>';
                })
                ->addColumn('voting_percentage', function ($row) use ($totalUsers) {
                    return $totalUsers > 0 ? round(($row->voting_turn_out / $totalUsers) * 100) . '%' : '0%';
                })
                ->addColumn('deadline', function ($row) {
                    return $row->deadline ? date('d M Y', strtotime($row->deadline)) : 'N/A';
                })
                ->addColumn('formatted_created_at', function ($row) {
                    return $row->created_at ? date('d M Y', strtotime($row->created_at)) : 'N/A';
                })
                ->addColumn('actions', function ($row) use ($id) {
                    $actions = '<div class="dropdown">
                        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item view-backlog" id="' . $row->id . '">View</a></li>
                            <li><a data-bs-toggle="offcanvas" href="#timeline" role="button" aria-controls="timeline" class="dropdown-item view-history-log">View Log</a></li>';

                    if ($row->status_id == 1 && $row->created_by == $id) {
                        $actions .= '<li><a id="' . $row->id . '" class="dropdown-item backlog-edit">Edit</a></li>
                            <li><a class="dropdown-item backlog-delete" id="' . $row->id . '">Delete</a></li>';
                    }

                    if ($row->created_by != $id) {
                        $backlogProject = BacklogProject::where('uploaded_by', $id)->where('backlog_id', $row->id)->first();
                        if (!$backlogProject) {
                            $actions .= '<li><a class="dropdown-item add-project-model" id="' . $row->id . '" data-bs-toggle="offcanvas" data-bs-target="#add_new_project" aria-controls="add_new_project">Contribute</a></li>';
                        } else {
                            $actions .= '<li>Contributed</li>';
                        }
                    }

                    $actions .= '</ul></div>';
                    return $actions;
                })
                ->addColumn('contribute', function ($row) use ($id) {
                    return BacklogProject::where('uploaded_by', $id)->where('backlog_id', $row->id)->count();
                })
                ->addColumn('total_users', function ($row) use ($totalUsers) {
                    return $totalUsers;
                })
                ->rawColumns(['status_badge', 'submission_link', 'actions'])
                ->make(true);
        }

        
        $data = array();

        $doing = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->where(function ($query) use ($id) {
                $query->where('backlogs.created_by', $id)
                    ->whereNotIn('backlogs.status_id', [3, 5])
                    ->orWhere(function ($q) {
                        $q->where('backlogs.status_id', 2);
                    });
            })
            ->withCount([
                'Projects as submission' => function ($query) {
                    $query->whereIn('status', ['1', '3']);
                }
            ])
            ->withCount([
                'Projects as voting_turn_out' => function ($query) {
                    $query->join('votes', 'backlog_projects.id', '=', 'votes.project_id')
                        ->selectRaw('COUNT(DISTINCT votes.user_id)');
                }
            ]);

        $doing = $this->recordFilter($doing, $filters, $id);
        $doing = $doing->orderBy('created_at', 'DESC')->get();
        $doing = $doing->map(function ($backlog) use ($totalUsers) {
            $backlog->total_users = $totalUsers;
            $backlog->voting_percentage = $totalUsers > 0
                ? ($backlog->voting_turn_out / $totalUsers) * 100
                : 0;
            return $backlog;
        });

        foreach ($doing as $record) {
            $backlog_id = $record->id;
            $isCount = BacklogProject::where('uploaded_by', $id)->where('backlog_id', $backlog_id)->count();
            $record['contribute'] = $isCount;
        }

        
        $done = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->where(function ($query) use ($id) {
                $query->where('status_id', 5)
                    ->where(function ($subQuery) use ($id) {
                        
                        $subQuery->where('created_by', $id)
                            ->orWhere('status_id', 5); 
                    });
            })
            ->withCount([
                'Projects as submission' => function ($query) {
                    $query->whereIn('status', ['1', '3']);
                }
            ])
            ->withCount([
                'Projects as voting_turn_out' => function ($query) {
                    $query->join('votes', 'backlog_projects.id', '=', 'votes.project_id')
                        ->selectRaw('COUNT(DISTINCT votes.user_id)');
                }
            ]);

        // Apply the same filters to done as you do to doing
        $done = $this->recordFilter($done, $filters, $id);
        $done = $done->orderBy('created_at', 'DESC')->get();
        $done = $done->map(function ($backlog) use ($totalUsers) {
            $backlog->total_users = $totalUsers;
            $backlog->voting_percentage = $totalUsers > 0
                ? ($backlog->voting_turn_out / $totalUsers) * 100
                : 0;
            return $backlog;
        });

        $totalBackLogs = Backlogs::where('created_by', $id)->count();
        $pendingBackLogs = Backlogs::where('created_by', $id)->where('status_id', 1)->count();
        $completedBackLogs = Backlogs::where('created_by', $id)->where('status_id', 5)->count();
        $approvedBackLogs = Backlogs::where('created_by', $id)->where('status_id', 2)->count();

        $data['doing'] = $doing;
        $data['done'] = $done;
        $data['total_backlogs'] = $totalBackLogs;
        $data['pending_backlogs'] = $pendingBackLogs;
        $data['completed_backlogs'] = $completedBackLogs;
        $data['approved_backlogs'] = $approvedBackLogs;
        return response()->json(['status' => 200, 'data' => $data]);
    }


    public function getRepositoryContents(Request $request)
    {
        try {
            $backlogId = $request->backlog_id;
            $path = $request->path ?? '';
            $branch = $request->branch ?? 'main';

            // Get the backlog
            $backlog = Backlogs::find($backlogId);

            if (!$backlog) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Backlog not found'
                ]);
            }

            // Check if backlog has GitHub repository
            if (!$backlog->github_repo_name) {
                return response()->json([
                    'status' => 400,
                    'message' => 'No GitHub repository found for this backlog'
                ]);
            }

            // Get repository contents
            $result = $this->githubService->getRepositoryContents(
                $backlog->github_repo_name,
                $path,
                $branch
            );

            if ($result['success']) {
                return response()->json([
                    'status' => 200,
                    'data' => $result,
                    'backlog' => [
                        'id' => $backlog->id,
                        'title' => $backlog->title,
                        'github_repo_name' => $backlog->github_repo_name,
                        'github_repo_url' => $backlog->github_repo_url
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => $result['error'] ?? 'Failed to get repository contents'
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in getRepositoryContents method", [
                'backlog_id' => $request->backlog_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while fetching repository contents'
            ]);
        }
    }

    public function getFileContent(Request $request)
    {
        try {
            $backlogId = $request->backlog_id;
            $filePath = $request->file_path;
            $branch = $request->branch ?? 'main';

            // Get the backlog
            $backlog = Backlogs::find($backlogId);

            if (!$backlog) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Backlog not found'
                ]);
            }

            // Check if backlog has GitHub repository
            if (!$backlog->github_repo_name) {
                return response()->json([
                    'status' => 400,
                    'message' => 'No GitHub repository found for this backlog'
                ]);
            }

            // Get file content
            $result = $this->githubService->getFileContent(
                $backlog->github_repo_name,
                $filePath,
                $branch
            );

            if ($result['success']) {
                return response()->json([
                    'status' => 200,
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => $result['error'] ?? 'Failed to get file content'
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in getFileContent method", [
                'backlog_id' => $request->backlog_id ?? null,
                'file_path' => $request->file_path ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while fetching file content'
            ]);
        }
    }

    public function getRepositoryBranches(Request $request)
    {
        try {
            $backlogId = $request->backlog_id;

            // Get the backlog
            $backlog = Backlogs::find($backlogId);

            if (!$backlog) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Backlog not found'
                ]);
            }

            // Check if backlog has GitHub repository
            if (!$backlog->github_repo_name) {
                return response()->json([
                    'status' => 400,
                    'message' => 'No GitHub repository found for this backlog'
                ]);
            }

            // Get repository branches
            $result = $this->githubService->getBranches($backlog->github_repo_name);

            if ($result['success']) {
                return response()->json([
                    'status' => 200,
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'status' => 500,
                    'message' => $result['error'] ?? 'Failed to get repository branches'
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in getRepositoryBranches method", [
                'backlog_id' => $request->backlog_id ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while fetching repository branches'
            ]);
        }
    }


    public function recordFilter($records, $filters, $id)
    {
        $hasFilters = false;

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            if ($filters['sort']['column'] == 'status') {
                $records->join('backlog_statuses', 'backlogs.status_id', '=', 'backlog_statuses.id')
                    ->orderBy('backlog_statuses.name', $filters['sort']['order']);
            } elseif ($filters['sort']['column'] == 'created_by') {
                $records->join('users', 'backlogs.created_by', '=', 'users.id')
                    ->orderBy('users.first_name', $filters['sort']['order']);
            } else {
                $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            }
            $hasFilters = true;
        }

        // Apply category filters
        if (!empty($filters['category'])) {
            $records->whereIn('backlogs.backlog_category_id', is_array($filters['category']) ? $filters['category'] : [$filters['category']]);
            $hasFilters = true;
        }

        // Apply status filters
        if (!empty($filters['status'])) {
            $records->whereIn('backlogs.status_id', is_array($filters['status']) ? $filters['status'] : [$filters['status']]);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'] . ' 00:00:00';
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }

        // Apply deadline range filters
        if (!empty($filters['deadline_date_range']['start']) && !empty($filters['deadline_date_range']['end'])) {
            $startDate = $filters['deadline_date_range']['start'] . ' 00:00:00';
            $endDate = $filters['deadline_date_range']['end'] . ' 23:59:59';
            $records->whereBetween('deadline', [$startDate, $endDate]);
            $hasFilters = true;
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('backlogs.title', 'LIKE', "%$search%")
                    ->orWhere('backlogs.description', 'LIKE', "%$search%")
                    ->orWhere('backlogs.id', 'LIKE', "%$search%")
                    ->orWhere('backlogs.deadline', 'LIKE', "%$search%")
                    ->orWhereHas('User', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('BacklogCategory', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('BacklogStatus', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%$search%");
                    });
            });
            $hasFilters = true;
        }

        // Apply default ordering if no filters were applied
        if (!$hasFilters) {
            $records->orderBy('created_at', 'DESC');
        }

        return $records;
    }

    public function viewBacklog(Request $request)
    {
        $id = $request->id;
        $records = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])->find($id);
        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function deleteBacklog(Request $request)
    {
        $id = $request->id;
        $data = Backlogs::find($id);
        $data->delete();
        return response()->json(['status' => 200, 'message' => 'Record Delete Successfully']);
    }

    public function editBacklogs(Request $request)
    {
        $id = $request->id;
        $data = Backlogs::find($id);
        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function rejectBacklog(Request $request)
    {
        $backlogId = $request->id;
        $userId = Auth()->user()->id;

        Log::info('Reject backlog request', [
            'backlog_id' => $backlogId,
            'user_id' => $userId,
            'request_data' => $request->all()
        ]);

        $backlog = Backlogs::find($backlogId);

        if (!$backlog) {
            return response()->json([
                'status' => 404,
                'message' => 'Backlog not found'
            ]);
        }

        Log::info('Backlog found', [
            'backlog_id' => $backlog->id,
            'created_by' => $backlog->created_by,
            'status_id' => $backlog->status_id,
            'current_user' => $userId
        ]);

        if (!in_array($backlog->status_id, [1, 2])) {
            return response()->json([
                'status' => 400,
                'message' => 'This backlog cannot be rejected in its current status'
            ]);
        }

        $backlog->status_id = 3;
        $backlog->save();

        logAction(
            LogConstants::Rejected,
            LogConstants::BACKLOG,
            $backlog->id,
            "Backlog id '{$backlog->id}' was rejected by user."
        );

        $totalPendingBacklogs = Backlogs::where('created_by', $userId)->where('status_id', 1)->count();
        $totalBacklogs = Backlogs::where('created_by', $userId)->count();

        return response()->json([
            'status' => 200,
            'message' => 'Backlog rejected successfully',
            'backlog_id' => $backlogId,
            'pending_count' => $totalPendingBacklogs,
            'total_count' => $totalBacklogs,
            'action' => 'rejected'
        ]);
    }
}