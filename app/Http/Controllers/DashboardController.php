<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_forms' => Form::count(),
            'active_forms' => Form::where('status', 'active')->count(),
            'total_submissions' => Submission::count(),
            'recent_submissions' => Submission::with('form')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ];

        return view('admin.dashboard', compact('stats'));
    }
}
