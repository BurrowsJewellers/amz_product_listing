<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SyncMonitoringController extends Controller
{
    /**
     * Display the sync monitoring dashboard
     */
    public function index()
    {
        return view('admin.sync-monitoring.index');
    }
}
