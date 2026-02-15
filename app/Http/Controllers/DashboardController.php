<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $servers = $user->servers()->count();
        $installations = $user->installations()->latest()->take(5)->with('server')->get();

        $stats = [
            'total_servers'       => $servers,
            'total_installations' => $user->installations()->count(),
            'successful'          => $user->installations()->where('status', 'success')->count(),
            'failed'              => $user->installations()->where('status', 'failed')->count(),
            'in_progress'         => $user->installations()->whereIn('status', ['pending', 'installing'])->count(),
        ];

        return view('dashboard', compact('stats', 'installations'));
    }
}
