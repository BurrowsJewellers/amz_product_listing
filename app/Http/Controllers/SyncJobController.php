<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SyncJob;

class SyncJobController extends Controller
{
    public static function markAsFinished($id)
    {
        return SyncJob::where('id', $id)->update(['status' => 0]);
    }

    public static function getJob($type, $marketplace): SyncJob
    {
        return SyncJob::firstOrCreate(['type' => $type, 'marketplace' => $marketplace]);
    }
}
