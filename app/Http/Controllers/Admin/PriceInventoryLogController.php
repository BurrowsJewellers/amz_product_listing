<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PriceInventoryLog;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables; // Import DataTables

class PriceInventoryLogController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = PriceInventoryLog::query();

            return DataTables::of($query)
                ->editColumn('created_at', function ($log) {
                    return $log->created_at->format('Y-m-d H:i:s');
                })
                ->editColumn('updated_at', function ($log) {
                    return $log->updated_at->format('Y-m-d H:i:s');
                })
                ->editColumn('status', function ($log) {
                    $color = $log->status == 'success' ? 'green' : 'red';

                    return '<span style="color:'.$color.';">'.ucfirst($log->status).'</span>';
                })
                ->editColumn('message', function ($log) {
                    return \Illuminate\Support\Str::limit($log->message, 100);
                })
                ->rawColumns(['status']) // Important for rendering HTML in status column
                ->make(true);
        }

        // For non-AJAX requests, you might still want to pass some initial data or just the view
        // The existing filter form will submit a GET request, reloading the page.
        // DataTables will then make an AJAX request.
        // So, the initial $logs variable for the view isn't strictly necessary if DataTables loads all data.
        // However, keeping the filter logic for non-JS or initial state can be useful.

        $filterData = $request->only(['marketplace', 'item_identifier', 'change_type', 'status', 'job_name']);
        // This $logs variable is for the non-DataTables part of the view (e.g. if JS fails)
        // or if you want to display something before DataTables loads.
        // For a pure DataTables implementation, this might not be used by the table itself.
        $initialQuery = PriceInventoryLog::latest();
        if ($request->filled('marketplace')) {
            $initialQuery->where('marketplace', $request->input('marketplace'));
        }
        if ($request->filled('item_identifier')) {
            $initialQuery->where('item_identifier', 'like', '%'.$request->input('item_identifier').'%');
        }
        if ($request->filled('change_type')) {
            $initialQuery->where('change_type', $request->input('change_type'));
        }
        if ($request->filled('status')) {
            $initialQuery->where('status', $request->input('status'));
        }
        if ($request->filled('job_name')) {
            $initialQuery->where('job_name', $request->input('job_name'));
        }
        $logs = $initialQuery->paginate(15); // Paginate for non-JS view, DataTables handles its own.

        return view('admin.price_inventory_logs.index', compact('logs', 'filterData'));
    }
}
