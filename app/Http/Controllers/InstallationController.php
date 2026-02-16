<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartInstallationRequest;
use App\Jobs\InstallWordPressJob;
use App\Models\Installation;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstallationController extends Controller
{
    /**
     * Show the installation form.
     */
    public function create(Request $request): View
    {
        $servers = $request->user()->servers()->where('status', 'active')->get();

        return view('installations.create', compact('servers'));
    }

    /**
     * Start a new WordPress + SSL installation.
     */
    public function store(StartInstallationRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Verify server ownership
        $server = Server::where('id', $validated['server_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Check for active installation on same server + domain
        $existing = Installation::where('server_id', $server->id)
            ->where('domain', $validated['domain'])
            ->whereIn('status', [Installation::STATUS_PENDING, Installation::STATUS_INSTALLING])
            ->exists();

        if ($existing) {
            return back()
                ->withInput()
                ->with('error', 'An installation is already in progress for this domain on this server.');
        }

        // Create installation record
        $installation = Installation::create([
            'server_id'   => $server->id,
            'user_id'     => $user->id,
            'domain'      => strtolower($validated['domain']),
            'admin_email' => $validated['admin_email'],
            'site_title'  => $validated['site_title'] ?? 'My WordPress Site',
            'php_version' => $validated['php_version'] ?? \App\Services\ScriptBuilder::DEFAULT_PHP_VERSION,
            'status'      => Installation::STATUS_PENDING,
        ]);

        // Dispatch async job
        InstallWordPressJob::dispatch($installation->id, $server->id);

        return redirect()
            ->route('installations.show', $installation)
            ->with('success', 'Installation queued! The process will begin shortly.');
    }

    /**
     * Show installation details and progress.
     */
    public function show(Request $request, Installation $installation): View
    {
        abort_unless($installation->user_id === $request->user()->id, 403);

        $installation->load('server');

        return view('installations.show', compact('installation'));
    }

    /**
     * Get installation status as JSON (for AJAX polling).
     */
    public function status(Request $request, Installation $installation): JsonResponse
    {
        abort_unless($installation->user_id === $request->user()->id, 403);

        return response()->json([
            'id'            => $installation->id,
            'status'        => $installation->status,
            'current_step'  => $installation->current_step,
            'progress'      => $installation->progress,
            'wp_admin_url'  => $installation->wp_admin_url,
            'error_message' => $installation->error_message,
            'log'           => $installation->log,
            'php_version'   => $installation->php_version,
            'started_at'    => $installation->started_at?->diffForHumans(),
            'completed_at'  => $installation->completed_at?->diffForHumans(),
        ]);
    }

    /**
     * List all installations for the authenticated user.
     */
    public function index(Request $request): View
    {
        $installations = $request->user()
            ->installations()
            ->with('server')
            ->latest()
            ->paginate(10);

        return view('installations.index', compact('installations'));
    }
}
