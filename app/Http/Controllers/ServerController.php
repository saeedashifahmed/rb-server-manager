<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerRequest;
use App\Models\Server;
use App\Services\SSHService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerController extends Controller
{
    /**
     * Display a listing of the user's servers.
     */
    public function index(Request $request): View
    {
        $servers = $request->user()
            ->servers()
            ->withCount('installations')
            ->latest()
            ->paginate(10);

        return view('servers.index', compact('servers'));
    }

    /**
     * Show the form for adding a new server.
     */
    public function create(): View
    {
        return view('servers.create');
    }

    /**
     * Store a new server.
     */
    public function store(StoreServerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $server = $request->user()->servers()->create([
            'name'        => $validated['name'],
            'ip_address'  => $validated['ip_address'],
            'ssh_port'    => $validated['ssh_port'],
            'ssh_username'=> $validated['ssh_username'],
            'ssh_private_key' => $validated['ssh_private_key'] ?? null,
            'ssh_password'    => $validated['ssh_password'] ?? null,
        ]);

        return redirect()
            ->route('servers.index')
            ->with('success', "Server \"{$server->name}\" added successfully.");
    }

    /**
     * Display a single server with its installations.
     */
    public function show(Request $request, Server $server): View
    {
        // Ensure the server belongs to the authenticated user
        abort_unless($server->user_id === $request->user()->id, 403);

        $installations = $server->installations()->latest()->paginate(10);

        return view('servers.show', compact('server', 'installations'));
    }

    /**
     * Test SSH connection to a server.
     */
    public function testConnection(Request $request, Server $server): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        try {
            $ssh = new SSHService();
            $ssh->testConnection($server);

            return back()->with('success', 'SSH connection successful!');
        } catch (\Throwable $e) {
            return back()->with('error', 'SSH connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a server.
     */
    public function destroy(Request $request, Server $server): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        $server->delete();

        return redirect()
            ->route('servers.index')
            ->with('success', 'Server deleted successfully.');
    }
}
