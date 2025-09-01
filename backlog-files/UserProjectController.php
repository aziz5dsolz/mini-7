<?php



namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BacklogProject;
use App\Models\Backlogs;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;
use App\Helpers\LogConstants;
use Illuminate\Support\Facades\Log;
use App\Services\GitHubService;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use App\Services\FileUploadService;


class UserProjectController extends Controller
{

    protected $githubService;
    protected $fileUploadService;

    public function __construct(GitHubService $githubService, FileUploadService $fileUploadService)
    {
        $this->githubService = $githubService;
        $this->fileUploadService = $fileUploadService;
    }

    public function index(Request $request)
    {

        $backlogs_id = $request->id;
        $id = Auth()->user()->id;
        $usedBacklogs = BacklogProject::where('uploaded_by', $id)->pluck('backlog_id')->toArray();
        // dd($usedBacklogs, $backlogs_id);
        // dd(today());
        $backlogs = Backlogs::whereIn('status_id', [2])
            ->where('created_by', '!=', $id)
            ->whereNotIn('id', $usedBacklogs)
            ->whereDate('deadline', '>', Carbon::today()->endOfDay())
            // ->where(function ($query) {
            //     // Only show backlogs that haven't reached their deadline or have no deadline
            //     $query
            //     //     ->orWhereNull('deadline');
            // }) 
            ->get();
        // dd($backlogs);
        // Add this before the return statement in your controller
        // dd([
        //     'user_id' => $id,
        //     'user_submitted_backlogs' => $usedBacklogs->toArray(),
        //     'total_backlogs_in_db' => Backlogs::count(),
        //     'approved_backlogs' => Backlogs::whereIn('status_id', [2, 4])->count(),
        //     'available_backlogs' => $backlogs->count(),
        //     'backlogs_data' => $backlogs->toArray()
        // ]);
        return view('user.projects', compact('backlogs', 'backlogs_id'));
    }

    public function saveProject(Request $request)
    {
        $id = Auth()->user()->id;
        $validatedData = $request->validate([
            'backlog_id' => 'required',
            'project_title' => 'required|max:100',
            'project_description' => 'required|max:255',
            'git_url' => 'required|max:255',
            'project_file' => 'required',
            'project_file.*' => 'file|max:102400',
        ]);

        $backlog_id = $request->backlog_id;

        try {
            // Create the project record
            $record = new BacklogProject();
            $record->title = $request->project_title;
            $record->description = $request->project_description;
            $record->git_url = $request->git_url;
            $record->uploaded_by = $id;
            $record->backlog_id = $backlog_id;
            $record->status = '0';

            // Handle file uploads (store locally for now)
            $uploadResults = ['local_files' => [], 'upload_data' => [], 'upload_type' => 'none'];

            if ($request->hasFile('project_file')) {
                $uploadResults = $this->fileUploadService->autoDetectAndHandleUploads($request, 'project_file');

                Log::info('Project file upload processing completed', [
                    'project_title' => $request->project_title,
                    'upload_type' => $uploadResults['upload_type'],
                    'total_files' => $uploadResults['total_files'],
                    'files_processed' => count($uploadResults['local_files'])
                ]);

                if (!empty($uploadResults['local_files'])) {
                    $projectFolder = '/uploads/projects/' . uniqid('project_' . $id . '_');
                    $absoluteFolder = public_path($projectFolder);
                    if (!File::exists($absoluteFolder)) {
                        File::makeDirectory($absoluteFolder, 0755, true);
                    }

                    foreach ($uploadResults['local_files'] as $filePath) {
                        $source = public_path($filePath);
                        $destination = $absoluteFolder . '/' . basename($filePath);
                        if (file_exists($source)) {
                            File::move($source, $destination);
                        }
                    }

                    $record->file = $projectFolder;
                }
            }

            $record->save();

            // Initialize response data
            $responseData = [
                'status' => 200,
                'message' => 'Project created successfully',
                'project_id' => $record->id,
                'github_operations' => [],
                'file_upload_results' => [
                    'upload_type' => $uploadResults['upload_type'],
                    'total_files_processed' => count($uploadResults['upload_data']),
                    'files_stored_locally' => count($uploadResults['local_files'])
                ]
            ];

            if (count($uploadResults['local_files']) > 1) {
                $responseData['message'] .= " ({$uploadResults['total_files']} files uploaded locally)";
            }

            try {
                $project = BacklogProject::with(['backlog', 'user'])->find($record->id);
                $repoName = $this->extractRepoNameFromUrl($project->git_url);

                if (!$repoName) {
                    Log::warning("Could not extract repository name from URL: {$project->git_url}");
                    $record->update(['collaboration_status' => 'invalid_github_url']);
                    $responseData['message'] .= ' (Warning: Invalid GitHub URL - branch creation skipped)';
                    $responseData['github_operations']['warning'] = 'Invalid GitHub URL format';

                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (invalid GitHub URL)."
                    );

                    return response()->json($responseData);
                }

                // Step 1: Create user branch
                $branchName = $this->generateBranchName($project);
                $branchResult = $this->githubService->createBranch($repoName, $branchName);

                if ($branchResult['success']) {
                    $responseData['github_operations']['branch_creation'] = 'success';
                    $responseData['github_operations']['branch_name'] = $branchName;
                    $responseData['message'] .= " Branch '{$branchName}' created for development.";

                    // Step 2: Add user as collaborator with pull permissions only
                    $userGithubUsername = $project->user->github_username ?? null;

                    if ($userGithubUsername) {
                        $collaboratorResult = $this->githubService->addCollaborator($repoName, $userGithubUsername, 'pull');

                        if ($collaboratorResult['success']) {
                            if (isset($collaboratorResult['invitation_sent']) && $collaboratorResult['invitation_sent']) {
                                $responseData['message'] .= " Collaboration invitation sent to {$userGithubUsername}.";
                                $responseData['github_operations']['collaboration_status'] = 'invitation_sent';
                                $collaborationStatus = 'invitation_sent';
                            } elseif (isset($collaboratorResult['already_collaborator']) && $collaboratorResult['already_collaborator']) {
                                $responseData['message'] .= " User {$userGithubUsername} already has repository access.";
                                $responseData['github_operations']['collaboration_status'] = 'already_collaborator';
                                $collaborationStatus = 'already_collaborator';
                            } else {
                                $responseData['message'] .= " User {$userGithubUsername} added as collaborator with read access.";
                                $responseData['github_operations']['collaboration_status'] = 'added';
                                $collaborationStatus = 'added';
                            }

                            Log::info("Successfully managed collaborator access for user {$userGithubUsername} on repository {$repoName}");
                        } else {
                            $responseData['message'] .= " Warning: Failed to add {$userGithubUsername} as collaborator - {$collaboratorResult['error']}";
                            $responseData['github_operations']['collaboration_status'] = 'failed';
                            $responseData['github_operations']['collaboration_error'] = $collaboratorResult['error'];
                            $collaborationStatus = 'failed';
                            Log::warning("Failed to add collaborator {$userGithubUsername} to {$repoName}: " . $collaboratorResult['error']);
                        }
                    } else {
                        $responseData['message'] .= " Warning: No GitHub username found - please add collaborator manually.";
                        $responseData['github_operations']['collaboration_status'] = 'no_github_username';
                        $collaborationStatus = 'no_github_username';
                        Log::warning("No GitHub username found for user {$project->user->id} in project {$record->id}");
                    }

                    // Step 3: Set up branch protection rules for main branch (if not already done)
                    $this->setupMainBranchProtection($repoName);

                    // Update project with GitHub info but keep status as pending
                    $record->update([
                        'github_branch' => $branchName,
                        'github_repo' => $repoName,
                        'collaboration_status' => $collaborationStatus ?? 'unknown'
                    ]);

                    $responseData['message'] .= " Files will be uploaded to GitHub when project is approved.";

                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files stored locally. Branch '{$branchName}' created in repository '{$repoName}'. Collaboration status: " . ($collaborationStatus ?? 'unknown') . ". Files NOT uploaded to GitHub (pending status)."
                    );
                } else {
                    $responseData['github_operations']['branch_creation'] = 'failed';
                    $responseData['github_operations']['branch_error'] = $branchResult['error'];
                    $responseData['message'] .= ' (Warning: Branch creation failed - please create manually)';

                    $record->update([
                        'github_repo' => $repoName,
                        'collaboration_status' => 'branch_creation_failed'
                    ]);

                    Log::error("Branch creation failed for project {$record->id}: " . $branchResult['error']);
                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (branch creation failed)."
                    );
                }
            } catch (\Exception $e) {
                Log::error("Error creating project with GitHub integration {$record->id}: " . $e->getMessage());

                $record->update(['collaboration_status' => 'github_operations_failed']);
                $responseData['message'] .= ' (Warning: GitHub integration failed)';
                $responseData['github_operations']['error'] = $e->getMessage();

                logAction(
                    LogConstants::CREATED,
                    LogConstants::PROJECT,
                    $record->id,
                    "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (GitHub operations failed)."
                );
            }

            return response()->json($responseData);
        } catch (Exception $e) {
            Log::error("Error creating project: " . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while creating the project: ' . $e->getMessage()
            ]);
        }
    }

    private function setupMainBranchProtection($repoName)
    {
        try {
            // Check if protection already exists
            $protectionCheck = $this->githubService->getBranchProtection($repoName, 'main');

            if ($protectionCheck['success'] && $protectionCheck['protection_rules']) {
                Log::info("Main branch protection already exists for {$repoName}");
                return;
            }

            // Set up branch protection rules
            $protectionRules = [
                'required_status_checks' => null,
                'enforce_admins' => false,
                'required_pull_request_reviews' => [
                    'required_approving_review_count' => 1,
                    'dismiss_stale_reviews' => false,
                    'require_code_owner_reviews' => false,
                    'require_last_push_approval' => false
                ],
                'restrictions' => null,
                'allow_force_pushes' => false,
                'allow_deletions' => false,
                'block_creations' => false,
                'required_conversation_resolution' => false
            ];

            $result = $this->githubService->enableBranchProtection($repoName, 'main', $protectionRules);

            if ($result['success']) {
                Log::info("Main branch protection enabled for {$repoName}");
            } else {
                Log::warning("Failed to enable main branch protection for {$repoName}: " . $result['error']);
            }
        } catch (\Exception $e) {
            Log::error("Error setting up main branch protection for {$repoName}: " . $e->getMessage());
        }
    }

    private function extractRepoNameFromUrl($githubUrl)
    {
        // Handle various GitHub URL formats
        $patterns = [
            '/github\.com\/[^\/]+\/([^\/\.]+)(?:\.git)?(?:\/.*)?$/',
            '/github\.com\/[^\/]+\/([^\/]+)\/.*$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $githubUrl, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function generateBranchName($project)
    {
        $backlogId = $project->backlog->id ?? 'backlog';
        $userId = $project->user->id ?? 'user';
        $userName = $project->user->first_name ?? 'user' . $userId;

        // Clean the username for branch naming
        $cleanUserName = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($userName));

        // Create branch name: feature/backlog-{id}-{username}-{timestamp}
        $timestamp = now()->format('Ymd-His');
        return "feature/backlog-{$backlogId}-{$cleanUserName}-{$timestamp}";
    }

    public function viewProject(Request $request)
    {
        $id = $request->id;
        $records = BacklogProject::with(['Backlog', 'User'])->withCount([
            'votes as total_votes'
        ])->where('id', $id)
            ->first();
        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function getProject(Request $request)
    {
        $id = Auth()->user()->id;
        $url_backlog_id = $request->url_backlog_id;
        $projectType = $request->project_type ?? 'mine';

        if ($request->ajax() && $request->has('project_type')) {
            $query = BacklogProject::with(['User', 'Backlog']);

            if ($projectType === 'mine') {
                $query->where(function ($q) use ($id) {
                    $q->where('backlog_projects.uploaded_by', $id)
                        ->whereIn('backlog_projects.status', ['0', '1', '3']);
                });
            } else {
                $query->where(function ($q) use ($id) {
                    $q->where('backlog_projects.uploaded_by', '!=', $id)
                        ->whereIn('backlog_projects.status', ['1', '3']);
                });
            }

            $query->withCount([
                'votes as is_voted' => function ($q) {
                    $q->where('user_id', auth()->id());
                },
                'votes as total_votes'
            ]);

            if ($url_backlog_id) {
                $query->where('backlog_id', $url_backlog_id);
            }

            $filters = json_decode($request->filters, true) ?? [];
            $query = $this->recordFilter($query, $filters, $id);

            return DataTables::of($query)
                ->addColumn('status_badge', function ($row) {
                    $statusClass = '';
                    $statusText = '';
                    switch ($row->status) {
                        case '0':
                            $statusClass = 'bg-secondary';
                            $statusText = 'Pending';
                            break;
                        case '1':
                            $statusClass = 'bg-primary';
                            $statusText = 'Approved';
                            break;
                        case '2':
                            $statusClass = 'bg-danger';
                            $statusText = 'Rejected';
                            break;
                        case '3':
                            $statusClass = 'bg-success';
                            $statusText = 'Completed';
                            break;
                    }
                    return '<span class="badge ' . $statusClass . '">' . $statusText . '</span>';
                })
                ->addColumn('user_name', function ($row) {
                    return $row->user->first_name . ' ' . $row->user->last_name;
                })
                ->addColumn('backlog_id', function ($row) {
                    return $row->backlog->id;
                })
                ->addColumn('votes_display', function ($row) {
                    return '<span class="view-voter-list" id="' . $row->id . '" title="View Project List" style="cursor: pointer; background-color: green; color: #fff; padding: .2rem .4rem; border-radius: 50px; font-weight: bold; text-decoration: underline;">' . $row->total_votes . '</span>';
                })
                ->addColumn('git_url_link', function ($row) {
                    return '<a href="' . $row->git_url . '" target="_blank">' . $row->git_url . '</a>';
                })
                ->addColumn('formatted_date', function ($row) {
                    return $row->created_at ? $row->created_at->format('d M Y') : 'N/A';
                })
                ->addColumn('actions', function ($row) use ($id) {
                    $actions = '<div class="dropdown">
                        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item view-project" id="' . $row->id . '">View</a></li>';

                    if ($row->uploaded_by != $id && $row->status != '3') {
                        if ($row->is_voted == 0) {
                            $actions .= '<li><a class="dropdown-item add-vote" id="' . $row->id . '">Vote</a></li>';
                        } else {
                            $actions .= '<li><a class="dropdown-item">Voted</a></li>';
                        }
                    }

                    $actions .= '<li><a data-bs-toggle="offcanvas" href="#timeline" role="button" aria-controls="timeline" class="dropdown-item view-history-log">View Log</a></li>
                        </ul>
                    </div>';

                    return $actions;
                })
                ->rawColumns(['status_badge', 'votes_display', 'git_url_link', 'actions'])
                ->make(true);
        }

        // Non-AJAX response for statistics
        $totalBackLogs = BacklogProject::where('uploaded_by', $id)->count();
        $pendingBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '0')->count();
        $approvedBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '1')->count();
        $rejectedBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '2')->count();

        $data = [
            'total_project' => $totalBackLogs,
            'pending_peoject' => $pendingBackLogs,
            'rejected_peoject' => $rejectedBackLogs,
            'approved_peoject' => $approvedBackLogs
        ];

        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function recordFilter($records, $filters, $id)
    {
        $hasFilters = false;

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            if ($filters['sort']['column'] == 'uploaded_by') {
                $records->join('users', 'backlog_projects.uploaded_by', '=', 'users.id')
                    ->orderBy('users.first_name', $filters['sort']['order']);
            } else {
                $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            }
            $hasFilters = true;
        }

        // Apply status filters
        if (isset($filters['status'])) {
            $records->where('status', $filters['status']);
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
                $query->where('backlog_projects.title', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.id', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.backlog_id', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.git_url', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.created_at', 'LIKE', "%$search%")
                    ->orWhereHas('User', function ($query) use ($search) {
                        $query->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    })
                    ->orWhereRaw("(SELECT COUNT(*) FROM votes WHERE backlog_projects.id = votes.project_id) LIKE '$search'");
            });
            $hasFilters = true;
        }

        if (!empty($filters['showDeletedBacklogs'])) {
            $records = BacklogProject::with(['User', 'Backlog'])
                ->where(function ($query) use ($id) {
                    $query->where('backlog_projects.uploaded_by', $id);
                })
                ->where('backlog_projects.status', '2')
                ->withCount([
                    'votes as is_voted' => function ($query) {
                        $query->where('user_id', auth()->id());
                    },
                    'votes as total_votes'
                ]);
        }

        return $records;
    }

    public function submitVote(Request $request)
    {
        try {
            $user_id = Auth::user()->id;
            $project_id = $request->project_id;
            $vote_type = $request->vote_type;
            $vote_mode = $request->vote_mode;
            $comment = $request->comment;

            $vote = new Vote;
            $vote->comment = $comment;
            $vote->vote_mode = $vote_mode;
            $vote->vote_type = $vote_type;
            $vote->user_id = $user_id;
            $vote->project_id = $project_id;
            $vote->save();
            logAction(
                LogConstants::VOTED,
                LogConstants::VOTE,
                $vote->id,
                "Vote cast on the project."
            );
            return response()->json(['status' => 200, 'message' => 'Vote Submitted Successfully']);
        } catch (Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }

    public function viewVoterList(Request $request)
    {
        $project_id = $request->project_id;
        $records = Vote::leftJoin('users', function ($join) {
            $join->on('votes.user_id', '=', 'users.id')
                ->where('votes.vote_mode', '!=', 'anonymous');
        })
            ->where('votes.project_id', $project_id)
            ->select('votes.*', 'users.first_name as user_name', 'users.last_name as last_name')
            ->get();

        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function rejectProject(Request $request)
    {
        $projectId = $request->id;
        $userId = Auth()->user()->id;

        // Debug: Log the request data
        Log::info('Reject project request', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'request_data' => $request->all()
        ]);

        // Find the project
        $project = BacklogProject::find($projectId);

        if (!$project) {
            return response()->json([
                'status' => 404,
                'message' => 'Project not found'
            ]);
        }

        // Debug: Log project details
        Log::info('Project found', [
            'project_id' => $project->id,
            'uploaded_by' => $project->uploaded_by,
            'status' => $project->status,
            'current_user' => $userId
        ]);

        // Check if project is in a rejectable status
        if (!in_array($project->status, ['0', '1'])) {
            return response()->json([
                'status' => 400,
                'message' => 'This project cannot be rejected in its current status'
            ]);
        }

        // Update the project status to rejected
        $project->status = '2';
        $project->save();

        // Log the action
        logAction(
            LogConstants::Rejected,
            LogConstants::PROJECT,
            $project->id,
            "Project id '{$project->id}' was rejected by user."
        );

        // Get updated counts for the user
        $totalPendingProjects = BacklogProject::where('uploaded_by', $userId)->where('status', '0')->count();
        $totalProjects = BacklogProject::where('uploaded_by', $userId)->count();

        return response()->json([
            'status' => 200,
            'message' => 'Project rejected successfully',
            'project_id' => $projectId,
            'pending_count' => $totalPendingProjects,
            'total_count' => $totalProjects,
            'action' => 'rejected'
        ]);
    }


    // Add this method to your UserProjectController class

    public function getProjectFiles(Request $request)
    {
        $projectId = $request->project_id;

        try {
            $project = BacklogProject::with(['User', 'Backlog'])->find($projectId);

            if (!$project) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Project not found'
                ]);
            }

            // Check if user has permission to view this project
            $currentUserId = Auth::user()->id;

            // Users can view:
            // 1. Their own projects (any status)
            // 2. Other users' approved/completed projects (status 1 or 3)
            if ($project->uploaded_by != $currentUserId && !in_array($project->status, ['1', '3'])) {
                return response()->json([
                    'status' => 403,
                    'message' => 'You do not have permission to view this project'
                ]);
            }

            $files = [];

            // Check if project is approved and has GitHub repository
            if ($project->status == '1' && $project->github_repo) {
                // For approved projects, fetch from GitHub
                $files = $this->getFilesFromGitHub($project);
            } else {
                // For pending projects or projects without GitHub integration, read from local storage
                $files = $this->getFilesFromLocalStorage($project);
            }

            return response()->json([
                'status' => 200,
                'data' => [
                    'project' => [
                        'id' => $project->id,
                        'title' => $project->title,
                        'description' => $project->description,
                        'status' => $project->status,
                        'source' => $project->status == '1' && $project->github_repo ? 'github' : 'local'
                    ],
                    'files' => $files
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error reading project files: " . $e->getMessage());

            return response()->json([
                'status' => 500,
                'message' => 'Error reading project files',
                'error' => $e->getMessage()
            ]);
        }
    }

    // private function getFilesFromGitHub($project)
    // {
    //     try {
    //         $repoName = $project->github_repo;

    //         if (!$repoName) {

    //             $repoName = $this->extractRepoNameFromUrl($project->git_url);
    //         }

    //         if (!$repoName) {
    //             Log::warning("No repository name found for project {$project->id}");
    //             return $this->getFilesFromLocalStorage($project);
    //         }


    //         $branchToUse = $project->github_branch;

    //         if (!$branchToUse) {
    //             // Fallback to default branch
    //             $branchResult = $this->githubService->getDefaultBranch($repoName);
    //             $branchToUse = $branchResult['success'] ? $branchResult['default_branch'] : 'main';
    //         }

    //         // Get repository contents from the user's branch
    //         $contentsResult = $this->githubService->getRepositoryContents($repoName, '', $branchToUse);

    //         if (!$contentsResult['success']) {
    //             Log::warning("Failed to get GitHub contents for project {$project->id} from branch {$branchToUse}: " . $contentsResult['error']);

    //             // Try fallback to main branch
    //             if ($branchToUse !== 'main') {
    //                 $contentsResult = $this->githubService->getRepositoryContents($repoName, '', 'main');
    //                 if ($contentsResult['success']) {
    //                     $branchToUse = 'main';
    //                 } else {
    //                     return $this->getFilesFromLocalStorage($project);
    //                 }
    //             } else {
    //                 return $this->getFilesFromLocalStorage($project);
    //             }
    //         }

    //         return $this->processGitHubContents($contentsResult['contents'], $repoName, $branchToUse, $project);
    //     } catch (\Exception $e) {
    //         Log::error("Error fetching files from GitHub for project {$project->id}: " . $e->getMessage());
    //         // Fallback to local storage
    //         return $this->getFilesFromLocalStorage($project);
    //     }
    // }


    // private function getFilesFromGitHub($project)
    // {
    //     try {
    //         // Only show GitHub files for approved projects (status = 1)
    //         if ($project->status !== '1') {
    //             Log::info("Project {$project->id} is not approved (status: {$project->status}), using local storage");
    //             return $this->getFilesFromLocalStorage($project);
    //         }

    //         $repoName = $project->github_repo;

    //         if (!$repoName) {
    //             $repoName = $this->extractRepoNameFromUrl($project->git_url);
    //         }

    //         if (!$repoName) {
    //             Log::warning("No repository name found for project {$project->id}");
    //             return $this->getFilesFromLocalStorage($project);
    //         }

    //         // Only use the user's specific branch - NO fallback to main
    //         $branchToUse = $project->github_branch;

    //         if (!$branchToUse) {
    //             Log::warning("No user branch found for project {$project->id}. Cannot show GitHub files without user branch.");
    //             return $this->getFilesFromLocalStorage($project);
    //         }

    //         // Get repository contents ONLY from the user's branch
    //         $contentsResult = $this->githubService->getRepositoryContents($repoName, '', $branchToUse);

    //         if (!$contentsResult['success']) {
    //             Log::warning("Failed to get GitHub contents for project {$project->id} from user branch {$branchToUse}: " . $contentsResult['error']);

    //             // DO NOT fallback to main branch - return local files instead
    //             return $this->getFilesFromLocalStorage($project);
    //         }

    //         Log::info("Successfully loaded GitHub files for project {$project->id} from user branch {$branchToUse}");
    //         return $this->processGitHubContents($contentsResult['contents'], $repoName, $branchToUse, $project);
    //     } catch (\Exception $e) {
    //         Log::error("Error fetching files from GitHub for project {$project->id}: " . $e->getMessage());
    //         // Fallback to local storage
    //         return $this->getFilesFromLocalStorage($project);
    //     }
    // }

    private function getFilesFromGitHub($project)
    {
        try {
            // Only show GitHub files for approved projects (status = 1)
            if ($project->status !== '1') {
                Log::info("Project {$project->id} is not approved (status: {$project->status}), using local storage");
                return $this->getFilesFromLocalStorage($project);
            }

            $repoName = $project->github_repo;

            if (!$repoName) {
                $repoName = $this->extractRepoNameFromUrl($project->git_url);
            }

            if (!$repoName) {
                Log::warning("No repository name found for project {$project->id}");
                return $this->getFilesFromLocalStorage($project);
            }

            // Only use the user's specific branch - NO fallback to main
            $branchToUse = $project->github_branch;

            if (!$branchToUse) {
                Log::warning("No user branch found for project {$project->id}. Cannot show GitHub files without user branch.");
                return $this->getFilesFromLocalStorage($project);
            }

            // Get only the files that were actually submitted by the user (not inherited from main)
            $userSubmittedFiles = $this->getUserSubmittedFiles($repoName, $branchToUse, $project);

            if (empty($userSubmittedFiles)) {
                Log::info("No user-submitted files found in GitHub for project {$project->id}, using local storage");
                return $this->getFilesFromLocalStorage($project);
            }

            Log::info("Successfully loaded user-submitted GitHub files for project {$project->id} from user branch {$branchToUse}");
            return $userSubmittedFiles;
        } catch (\Exception $e) {
            Log::error("Error fetching files from GitHub for project {$project->id}: " . $e->getMessage());
            // Fallback to local storage
            return $this->getFilesFromLocalStorage($project);
        }
    }

    /**
     * Get only the files that were actually submitted by the user (not inherited from main branch)
     */
    private function getUserSubmittedFiles($repoName, $userBranch, $project)
    {
        try {
            // Get the commit differences between user branch and main branch
            $compareResult = $this->githubService->compareBranches($repoName, 'main', $userBranch);

            if (!$compareResult['success']) {
                Log::warning("Failed to compare branches for project {$project->id}: " . $compareResult['error']);
                return [];
            }

            // Get the list of files that were added or modified by the user
            $changedFiles = $compareResult['files'] ?? [];

            if (empty($changedFiles)) {
                Log::info("No file changes found between main and user branch for project {$project->id}");
                return [];
            }

            $userFiles = [];

            foreach ($changedFiles as $fileChange) {
                // Only include files that were added or modified (not deleted)
                if (in_array($fileChange['status'], ['added', 'modified'])) {
                    $filePath = $fileChange['filename'];

                    // Get the actual file content from user branch
                    $fileResult = $this->githubService->getFileContent($repoName, $filePath, $userBranch);

                    if ($fileResult['success']) {
                        $pathParts = explode('/', $filePath);
                        $fileName = end($pathParts);
                        $isReadable = $this->fileUploadService->isTextFile($fileName);

                        $userFiles[] = [
                            'name' => $fileName,
                            'path' => $filePath,
                            'type' => 'file',
                            'size' => $this->fileUploadService->formatFileSize(strlen($fileResult['content'])),
                            'content' => $isReadable ? $fileResult['content'] : null,
                            'is_readable' => $isReadable,
                            'sha' => $fileChange['sha'] ?? null,
                            'download_url' => $fileChange['blob_url'] ?? null,
                            'html_url' => $fileChange['blob_url'] ?? null,
                            'message' => !$isReadable ? 'Binary file - cannot display content' : null,
                            'change_status' => $fileChange['status'] // added, modified, etc.
                        ];
                    }
                }
            }

            // Sort files alphabetically
            usort($userFiles, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            return $userFiles;
        } catch (\Exception $e) {
            Log::error("Error getting user submitted files for project {$project->id}: " . $e->getMessage());
            return [];
        }
    }

    private function getFilesFromLocalStorage($project)
    {
        $files = [];
        $filePath = public_path($project->file);

        if (file_exists($filePath)) {
            if (is_dir($filePath)) {

                $files = $this->fileUploadService->scanDirectory($filePath);
            } else {

                $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);

                if (in_array(strtolower($fileExtension), ['zip', 'rar', '7z'])) {

                    $files = $this->fileUploadService->extractAndReadArchive($filePath, $fileExtension);
                } else {

                    $files = $this->fileUploadService->readSingleFile($filePath);
                }
            }
        }

        return $files;
    }

    private function processGitHubContents($contents, $repoName, $branch, $project, $currentPath = '')
    {
        $files = [];

        foreach ($contents as $item) {
            if ($item['type'] === 'dir') {
                // Get directory contents
                $dirContents = $this->githubService->getRepositoryContents($repoName, $item['path'], $branch);

                if ($dirContents['success']) {
                    $files[] = [
                        'name' => $item['name'],
                        'path' => $item['path'],
                        'type' => 'folder',
                        'children' => $this->processGitHubContents($dirContents['contents'], $repoName, $branch, $project, $item['path'])
                    ];
                }
            } else {
                // It's a file
                $fileContent = null;
                $isReadable = $this->fileUploadService->isTextFile($item['name']);

                if ($isReadable && $item['size'] < 1024 * 1024) {
                    $fileResult = $this->githubService->getFileContent($repoName, $item['path'], $branch);
                    if ($fileResult['success']) {
                        $fileContent = $fileResult['content'];
                    }
                }

                $files[] = [
                    'name' => $item['name'],
                    'path' => $item['path'],
                    'type' => 'file',
                    'size' => $this->fileUploadService->formatFileSize($item['size']),
                    'content' => $fileContent,
                    'is_readable' => $isReadable,
                    'sha' => $item['sha'] ?? null,
                    'download_url' => $item['download_url'] ?? null,
                    'html_url' => $item['html_url'] ?? null,
                    'message' => !$isReadable ? 'Binary file - cannot display content' : null
                ];
            }
        }

        // Sort files: folders first, then files, both alphabetically
        usort($files, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $files;
    }
}
