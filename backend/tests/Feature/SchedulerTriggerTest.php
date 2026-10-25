<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SchedulerTriggerTest extends TestCase
{
    public function test_endpoint_is_hidden_when_no_token_is_configured(): void
    {
        config(['services.scheduler.token' => '']);
        Artisan::shouldReceive('call')->never();

        $this->postJson('/api/internal/scheduler/run', [], ['X-Scheduler-Token' => ''])->assertNotFound();
    }

    public function test_wrong_or_missing_token_is_refused(): void
    {
        config(['services.scheduler.token' => 'secret-token']);
        Artisan::shouldReceive('call')->never();

        $this->postJson('/api/internal/scheduler/run')->assertNotFound();
        $this->postJson('/api/internal/scheduler/run', [], ['X-Scheduler-Token' => 'wrong'])->assertNotFound();
    }

    public function test_valid_token_runs_the_scheduler(): void
    {
        config(['services.scheduler.token' => 'secret-token']);
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);

        $this->postJson('/api/internal/scheduler/run', [], ['X-Scheduler-Token' => 'secret-token'])
            ->assertOk()->assertJson(['status' => 'success', 'exit_code' => 0]);
    }
}
