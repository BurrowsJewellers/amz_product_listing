<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Sync Monitoring Controller
 *
 * Provides access to the sync monitoring dashboard for viewing
 * and managing sync failures.
 *
 * Note: This controller is already protected by the 'auth' middleware.
 * If you need role-based access control (e.g., admin-only access),
 * add a middleware here or create a policy.
 *
 * Example with custom middleware:
 *   public function __construct()
 *   {
 *       $this->middleware('admin');
 *   }
 *
 * Example with policy:
 *   public function index()
 *   {
 *       $this->authorize('viewSyncMonitoring');
 *       return view('admin.sync-monitoring.index');
 *   }
 */
class SyncMonitoringController extends Controller
{
    /**
     * Display the sync monitoring dashboard
     *
     * This dashboard provides:
     * - Real-time statistics on sync failures
     * - Filterable table of failure logs
     * - One-click retry functionality for failed items
     * - Detailed failure information including API requests/responses
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin.sync-monitoring.index');
    }
}
