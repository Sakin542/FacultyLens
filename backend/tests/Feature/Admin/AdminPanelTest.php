<?php

namespace Tests\Feature\Admin;

use App\Models\AiUsageLog;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\Admin\FeatureFlagService;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected const ADMIN_EMAIL = 'admin@facultylens.edu';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.email' => self::ADMIN_EMAIL]);
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'model_loaded' => true])]);
        $this->admin = User::factory()->create([
            'email' => self::ADMIN_EMAIL,
            'password' => 'Admin123!',
            'role' => 'ADMIN',
        ]);
    }

    // ------------------------------------------------------------------ admin lock

    public function test_only_the_configured_admin_email_counts_as_admin(): void
    {
        $impostor = User::factory()->create(['role' => 'ADMIN', 'email' => 'someone@aust.edu']);

        $this->assertTrue($this->admin->isAdmin());
        $this->assertFalse($impostor->isAdmin());

        $this->actingAs($impostor, 'sanctum')->getJson('/api/admin/overview')->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/overview')->assertOk();
    }

    public function test_admin_login_accepts_the_admin_and_refuses_everyone_else(): void
    {
        $faculty = User::factory()->create(['email' => 'faculty@aust.edu', 'password' => 'Password123!']);

        $this->postJson('/api/admin/auth/login', ['email' => $faculty->email, 'password' => 'Password123!'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This sign-in page is for the FacultyLens administrator only.');
        $this->assertGuest('web');

        $this->postJson('/api/admin/auth/login', ['email' => self::ADMIN_EMAIL, 'password' => 'wrong-password'])->assertStatus(401);

        $this->postJson('/api/admin/auth/login', ['email' => self::ADMIN_EMAIL, 'password' => 'Admin123!'])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true);
        $this->assertAuthenticatedAs($this->admin, 'web');
    }

    public function test_faculty_cannot_reach_any_admin_endpoint(): void
    {
        $faculty = User::factory()->create();
        foreach (['/api/admin/overview', '/api/admin/users', '/api/admin/settings', '/api/admin/backups', '/api/admin/ai-usage'] as $url) {
            $this->actingAs($faculty, 'sanctum')->getJson($url)->assertStatus(403);
        }
        auth()->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->getJson('/api/admin/overview')->assertStatus(401);
    }

    public function test_admin_ensure_command_creates_admin_and_demotes_other_admins(): void
    {
        $this->admin->delete();
        $other = User::factory()->create(['role' => 'ADMIN', 'email' => 'old-admin@aust.edu']);
        config(['admin.password' => 'Admin123!']);

        $this->artisan('admin:ensure')->assertSuccessful();

        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->assertSame('ADMIN', $admin->role);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Admin123!', $admin->password));
        $this->assertSame('FACULTY', $other->fresh()->role);
    }

    // ------------------------------------------------------------------ overview

    public function test_overview_reports_real_kpis_with_period_comparison(): void
    {
        User::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/overview?days=7')
            ->assertOk()
            ->assertJsonPath('data.kpis.users_total.value', 4)
            ->assertJsonPath('data.kpis.new_users.current', 4)
            ->assertJsonPath('data.kpis.new_users.previous', 0)
            ->assertJsonPath('data.kpis.ai_availability.value', null) // no recorded checks yet: unknown, not 100%
            ->assertJsonStructure(['data' => [
                'range' => ['days', 'start', 'end', 'previous_start'],
                'kpis' => ['users_total', 'active_faculty', 'suspended_accounts', 'departments', 'courses', 'assessments', 'analyses_completed', 'analyses_failed',
                    'ai_calls', 'documents', 'pending_jobs', 'failed_jobs', 'email_failures', 'open_alerts', 'open_incidents', 'data_quality_open', 'last_backup'],
                'series' => ['new_users', 'ai_calls', 'analyses', 'emails', 'failed_jobs', 'admin_activity'],
                'account_status', 'services', 'critical_alerts', 'recent_activity', 'freshness',
            ]])
            ->assertJsonCount(8, 'data.series.new_users');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/overview?days=12')->assertStatus(422);
    }

    // ------------------------------------------------------------------ user management

    public function test_admin_can_assign_department_head_and_deactivate_a_user(): void
    {
        $faculty = User::factory()->create(['password' => 'Password123!']);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/users/{$faculty->id}", ['role' => 'DEPARTMENT_HEAD'])
            ->assertOk()->assertJsonPath('data.role', 'DEPARTMENT_HEAD');
        $this->assertTrue($faculty->fresh()->isDepartmentHead());

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/users/{$faculty->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/users/{$faculty->id}", ['is_active' => false, 'reason' => 'Left the university'])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_USER_SUSPENDED', 'entity_id' => $faculty->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_ROLE_CHANGED', 'entity_id' => $faculty->id]);

        // Deactivated: sign-in refused, and an existing session is ended on its next request
        $this->postJson('/api/auth/login', ['email' => $faculty->email, 'password' => 'Password123!'])
            ->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
        $this->actingAs($faculty->fresh(), 'sanctum')->getJson('/api/courses')
            ->assertStatus(401)->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_admin_role_cannot_be_granted_and_admin_cannot_be_changed(): void
    {
        $faculty = User::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/users/{$faculty->id}", ['role' => 'ADMIN'])
            ->assertStatus(422);
        $this->assertSame('FACULTY', $faculty->fresh()->role ?? 'FACULTY');

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/admin/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422);
        $this->assertTrue($this->admin->fresh()->isActive());
    }

    public function test_admin_can_delete_an_account_after_confirming_its_email(): void
    {
        $faculty = User::factory()->create(['email' => 'temp.account@aust.edu']);
        $faculty->createToken('device');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$faculty->id}", ['confirm_email' => 'wrong@aust.edu', 'reason' => 'Test account'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_email');
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$faculty->id}", ['confirm_email' => 'temp.account@aust.edu'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertDatabaseHas('users', ['id' => $faculty->id]);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$faculty->id}", ['confirm_email' => 'Temp.Account@aust.edu', 'reason' => 'Test account'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $faculty->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $faculty->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_USER_DELETED', 'user_id' => $this->admin->id]);
    }

    public function test_admin_account_and_course_owners_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$this->admin->id}", ['confirm_email' => self::ADMIN_EMAIL, 'reason' => 'x'])
            ->assertStatus(422);

        $owner = User::factory()->create(['email' => 'owner@aust.edu']);
        \App\Models\Course::create(['user_id' => $owner->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/users/{$owner->id}", ['confirm_email' => 'owner@aust.edu', 'reason' => 'Left'])
            ->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);

        $faculty = User::factory()->create();
        $this->actingAs($faculty, 'sanctum')->deleteJson("/api/admin/users/{$owner->id}", ['confirm_email' => 'owner@aust.edu', 'reason' => 'x'])
            ->assertStatus(403);
    }

    public function test_user_list_supports_search_and_filters(): void
    {
        User::factory()->create(['name' => 'Dr. Nusrat Jahan', 'department' => 'EEE']);
        User::factory()->create(['name' => 'Dr. Karim', 'is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users?search=Nusrat')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Dr. Nusrat Jahan');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users?status=deactivated')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Dr. Karim');
        // The administrator is always listed first
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/users')
            ->assertOk()->assertJsonPath('data.0.is_admin', true);
    }

    // ------------------------------------------------------------------ feature toggles

    public function test_turning_registration_off_blocks_sign_up_server_side(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/settings', ['features' => ['registration' => false, 'announcement' => 'Maintenance on Friday']])
            ->assertOk();

        $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('data.features.registration', false)
            ->assertJsonPath('data.features.announcement', 'Maintenance on Friday');

        auth()->forgetGuards();
        $this->postJson('/api/auth/register', [
            'name' => 'New', 'email' => 'new@aust.edu', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'department' => 'CSE', 'designation' => 'Lecturer',
        ])->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_disabled_ai_feature_blocks_new_work_but_not_reads(): void
    {
        app(FeatureFlagService::class)->update(['academic_chat' => false]);
        $faculty = User::factory()->create();

        $this->actingAs($faculty, 'sanctum')->getJson('/api/academic-chat/sessions')->assertOk();
        $this->actingAs($faculty, 'sanctum')->postJson('/api/academic-chat/sessions', [])
            ->assertStatus(403)->assertJsonPath('feature', 'academic_chat');
    }

    // ------------------------------------------------------------------ backups

    public function test_backup_failure_is_recorded_without_leaking_details(): void
    {
        // The test database is in-memory SQLite, which cannot be backed up
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/backups')
            ->assertStatus(500)->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseHas('database_backups', ['status' => 'failed', 'trigger' => 'manual', 'triggered_by' => $this->admin->id]);
    }

    public function test_backups_can_be_listed_downloaded_deleted_and_pruned(): void
    {
        Storage::fake('local');
        config(['admin.backups.keep' => 2]);
        foreach (range(1, 3) as $i) {
            Storage::disk('local')->put("backups/b{$i}.sql.gz", gzencode("-- backup {$i}"));
            DatabaseBackup::query()->create([
                'filename' => "b{$i}.sql.gz", 'disk' => 'local', 'path' => "backups/b{$i}.sql.gz", 'size_bytes' => 20,
                'status' => 'completed', 'trigger' => 'scheduled', 'started_at' => now()->subDays(4 - $i), 'completed_at' => now()->subDays(4 - $i),
            ]);
        }

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/backups')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.last_completed.filename', 'b3.sql.gz');

        $latest = DatabaseBackup::where('filename', 'b3.sql.gz')->first();
        $this->actingAs($this->admin, 'sanctum')->get("/api/admin/backups/{$latest->id}/download")
            ->assertOk()->assertDownload('b3.sql.gz');

        $this->assertSame(1, app(\App\Services\Admin\DatabaseBackupService::class)->prune());
        $this->assertDatabaseMissing('database_backups', ['filename' => 'b1.sql.gz']);
        Storage::disk('local')->assertMissing('backups/b1.sql.gz');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/backups/{$latest->id}")->assertOk();
        Storage::disk('local')->assertMissing('backups/b3.sql.gz');
    }

    /** The in-memory test database cannot be dumped, so the dump step writes a small gzip file instead. */
    protected function backupServiceWithFakeDump(): \App\Services\Admin\DatabaseBackupService
    {
        $service = new class extends \App\Services\Admin\DatabaseBackupService {
            protected function copySqlite(array $config, string $absolute): void
            {
                file_put_contents($absolute, gzencode('-- test dump'));
            }
        };
        $this->app->instance(\App\Services\Admin\DatabaseBackupService::class, $service);

        return $service;
    }

    public function test_backups_include_the_uploaded_files_and_verify_both_archives(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension not installed here (the Docker image and CI have it).');
        }
        Storage::fake('local');
        $this->backupServiceWithFakeDump();
        Storage::disk('local')->put('documents/syllabus.pdf', '%PDF-1.4 syllabus');
        Storage::disk('local')->put('question_papers/midterm.txt', '1. Define a graph. [5]');
        Storage::disk('local')->put('ai-toolkit/1/temp.mp3', 'temporary upload');   // not application data: left out
        Storage::disk('local')->put('backups/old.sql.gz', gzencode('older backup'));  // never back up the backups

        $data = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/backups')->assertCreated()->json('data');
        $this->assertTrue($data['has_files']);
        $this->assertSame(2, $data['files_count']);

        $backup = DatabaseBackup::query()->findOrFail($data['id']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->files_path)) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame(['documents/syllabus.pdf', 'question_papers/midterm.txt'], $names);

        $files = preg_replace('/\.sql\.gz$/', '', $backup->filename).'.files.zip';
        $this->actingAs($this->admin, 'sanctum')->get("/api/admin/backups/{$backup->id}/download?part=files")->assertOk()->assertDownload($files);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/backups/{$backup->id}/verify")->assertOk();

        Storage::disk('local')->delete($backup->files_path);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/backups/{$backup->id}/verify")->assertStatus(422)
            ->assertJsonPath('message', 'Verification failed: The uploaded-files archive is missing from storage.');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/admin/backups/{$backup->id}")->assertOk();
        Storage::disk('local')->assertMissing($backup->path);
    }

    public function test_scheduled_backups_catch_up_after_the_machine_was_off(): void
    {
        Storage::fake('local');
        $this->backupServiceWithFakeDump();
        config(['admin.backups.interval_hours' => 24, 'admin.backups.include_files' => false]);

        // Checked hourly, not once at 02:00: Laravel never runs a schedule that was missed
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'backup:database --scheduled --if-due'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);

        DatabaseBackup::query()->create(['filename' => 'recent.sql.gz', 'disk' => 'local', 'path' => 'backups/recent.sql.gz', 'size_bytes' => 10,
            'status' => 'completed', 'trigger' => 'scheduled', 'started_at' => now()->subHours(3), 'completed_at' => now()->subHours(3)]);
        $this->artisan('backup:database --scheduled --if-due')->expectsOutput('A recent backup exists; nothing to do.')->assertSuccessful();
        $this->assertSame(1, DatabaseBackup::query()->count());

        $this->travel(22)->hours();   // newest backup is now 25 h old: the next hourly check backs up
        $this->artisan('backup:database --scheduled --if-due')->assertSuccessful();
        $this->assertSame(2, DatabaseBackup::query()->count());
        $this->assertSame('scheduled', DatabaseBackup::query()->latest('id')->first()->trigger);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/backups')->assertOk()
            ->assertJsonPath('meta.interval_hours', 24)->assertJsonPath('meta.include_files', false);
    }

    // ------------------------------------------------------------------ AI usage

    public function test_ai_calls_are_metered_per_user_without_storing_content(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'model_loaded' => true]),
            '*' => Http::response(['status' => 'success', 'data' => ['embedding_dim' => 384]]),
        ]);
        $faculty = User::factory()->create();
        $this->actingAs($faculty);

        app(AiService::class)->generateEmbedding('Explain normalization in relational databases.');

        $log = AiUsageLog::query()->latest('id')->firstOrFail();
        $this->assertSame($faculty->id, $log->user_id);
        $this->assertTrue($log->success);
        $this->assertGreaterThan(0, $log->estimated_tokens);
        $this->assertStringNotContainsString('normalization', json_encode($log->toArray()));

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/ai-usage?days=7')
            ->assertOk()
            ->assertJsonPath('data.totals.calls', 1)
            ->assertJsonPath('data.by_user.0.user_id', $faculty->id);
    }

    public function test_operation_names_are_derived_from_ai_paths(): void
    {
        $this->assertSame('chat_academic', \App\Services\Admin\AiUsageRecorder::operationFor('/api/v1/chat/academic'));
        $this->assertSame('analyze_question', \App\Services\Admin\AiUsageRecorder::operationFor('/analyze-question'));
    }
}
