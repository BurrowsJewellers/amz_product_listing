<?php

namespace Tests\Unit;

use App\Models\SyncJob;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * isStuck() drives lock recovery. The production incident left
 * getProductsFromEWebAmazon with status=1 and NULL started_at/last_heartbeat/process_id
 * (it set status=1 without startJob, then the process died) — and isStuck() returned
 * false, so it could never be recovered and blocked the whole amazon-sync chain.
 *
 * Pure model logic — no database access.
 */
class SyncJobIsStuckTest extends TestCase
{
    private function job(array $attrs): SyncJob
    {
        $job = new SyncJob;
        foreach ($attrs as $k => $v) {
            $job->{$k} = $v;
        }

        return $job;
    }

    public function test_not_running_is_never_stuck(): void
    {
        $job = $this->job(['status' => 0, 'started_at' => Carbon::now()->subHours(5)]);
        $this->assertFalse($job->isStuck());
    }

    public function test_orphaned_lock_with_null_timing_is_stuck(): void
    {
        // status=1 but no started_at / heartbeat to age out — the exact orphan state.
        $job = $this->job(['status' => 1, 'started_at' => null, 'last_heartbeat' => null]);
        $this->assertTrue($job->isStuck(), 'A running job with no timing info is an orphaned lock and must be recoverable.');
    }

    public function test_fresh_running_job_is_not_stuck(): void
    {
        $job = $this->job([
            'status' => 1,
            'started_at' => Carbon::now(),
            'last_heartbeat' => Carbon::now(),
            'timeout_minutes' => 30,
        ]);
        $this->assertFalse($job->isStuck());
    }

    public function test_stale_heartbeat_is_stuck(): void
    {
        $job = $this->job([
            'status' => 1,
            'started_at' => Carbon::now()->subMinutes(10),
            'last_heartbeat' => Carbon::now()->subMinutes(10),
            'timeout_minutes' => 60,
        ]);
        $this->assertTrue($job->isStuck());
    }

    public function test_exceeded_timeout_is_stuck(): void
    {
        $job = $this->job([
            'status' => 1,
            'started_at' => Carbon::now()->subMinutes(40),
            'last_heartbeat' => Carbon::now(), // fresh heartbeat, but past timeout
            'timeout_minutes' => 30,
        ]);
        $this->assertTrue($job->isStuck());
    }
}
