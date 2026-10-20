<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAlert;
use App\Models\AdminOperation;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\DatabaseBackup;
use App\Models\DataQualityFinding;
use App\Models\HealthCheckResult;
use App\Models\MaintenanceWindow;
use App\Models\Question;
use App\Models\User;
use App\Services\Admin\AlertEvaluator;
use App\Services\Admin\DatabaseBackupService;
use App\Services\Admin\FailedJobInspector;
use App\Services\Admin\ServiceMonitor;
use App\Support\SafeCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminControlCenterTest extends TestCase
{
    use RefreshDatabase;

    protected const ADMIN_EMAIL = 'rashid.cse.20230104102@aust.edu';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.email' => self::ADMIN_EMAIL]);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['email' => self::ADMIN_EMAIL, 'password' => 'Admin123!', 'role' => 'ADMIN']);
    }

    protected function fakeAi(int ...$statuses): void
    {
        $sequence = Http::sequence();
        foreach ($statuses ?: [200] as $status) {
            $sequence->push($status === 200 ? ['status' => 'ok', 'model' => 'all-MiniLM-L6-v2', 'model_loaded' => true] : [], $status);
        }
        Http::fake(['*/health' => $sequence]);
    }

    protected function asAdmin(): static
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    protected function course(User $owner, array $attrs = []): Course
    {
        return Course::query()->create($attrs + [
            'user_id' => $owner->id, 'course_code' => 'CSE'.random_int(100, 999), 'course_name' => 'Algorithms',
            'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------ authorization

    public function test_every_new_admin_endpoint_rejects_faculty_and_guests(): void
    {
        $faculty = User::factory()->create();
        $endpoints = [
            ['get', '/api/admin/search?q=ab'], ['get', '/api/admin/users/'.$faculty->id], ['post', '/api/admin/users'], ['post', '/api/admin/users/bulk'],
            ['get', '/api/admin/access'], ['get', '/api/admin/audit-logs'], ['get', '/api/admin/security'], ['get', '/api/admin/institution'],
            ['get', '/api/admin/institution/courses'], ['get', '/api/admin/system/health'], ['post', '/api/admin/system/health/check'],
            ['get', '/api/admin/system/environment'], ['get', '/api/admin/system/schedule'], ['get', '/api/admin/queues'], ['get', '/api/admin/queues/failed'],
            ['get', '/api/admin/email'], ['get', '/api/admin/alerts'], ['get', '/api/admin/incidents'], ['post', '/api/admin/incidents'],
            ['get', '/api/admin/operations'], ['post', '/api/admin/exports'], ['get', '/api/admin/maintenance'], ['get', '/api/admin/data-quality'],
            ['post', '/api/admin/data-quality/scan'], ['get', '/api/admin/ai'],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->actingAs($faculty, 'sanctum')->json($method, $url)->assertStatus(403);
        }
        auth()->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();
        foreach ($endpoints as [$method, $url]) {
            $this->json($method, $url)->assertStatus(401);
        }
    }

    // ------------------------------------------------------------------ user lifecycle

    public function test_admin_creates_an_account_and_the_user_gets_a_set_password_link(): void
    {
        $this->asAdmin()->postJson('/api/admin/users', ['name' => 'New Faculty', 'email' => 'New.Faculty@aust.edu', 'department' => 'CSE', 'role' => 'DEPARTMENT_HEAD'])
            ->assertCreated()->assertJsonPath('data.email', 'new.faculty@aust.edu')->assertJsonPath('data.role', 'DEPARTMENT_HEAD');

        $user = User::query()->where('email', 'new.faculty@aust.edu')->firstOrFail();
        $this->assertDatabaseHas('email_deliveries', ['user_id' => $user->id, 'type' => 'PASSWORD_RESET']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_USER_CREATED', 'entity_id' => $user->id]);

        // Duplicate (case-insensitive) is rejected
        $this->asAdmin()->postJson('/api/admin/users', ['name' => 'Dup', 'email' => 'NEW.faculty@aust.edu'])->assertStatus(422);
        // ADMIN can never be assigned
        $this->asAdmin()->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@aust.edu', 'role' => 'ADMIN'])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_user_detail_shows_history_and_sessions_without_secrets(): void
    {
        $faculty = User::factory()->create(['department' => 'EEE']);
        $faculty->createToken('device');
        $this->asAdmin()->patchJson("/api/admin/users/{$faculty->id}", ['department' => 'CSE', 'designation' => 'Lecturer'])->assertOk();

        $res = $this->asAdmin()->getJson("/api/admin/users/{$faculty->id}")->assertOk()
            ->assertJsonPath('data.department', 'CSE')
            ->assertJsonPath('data.sessions.api_tokens', 1)
            ->assertJsonPath('data.history.0.action', 'ADMIN_USER_UPDATED');
        $body = $res->getContent();
        foreach (['password', 'two_factor_secret', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $body);
        }
    }

    public function test_revoking_sessions_deletes_tokens_and_is_audited(): void
    {
        $faculty = User::factory()->create();
        $faculty->createToken('a');
        $faculty->createToken('b');

        $this->asAdmin()->postJson("/api/admin/users/{$faculty->id}/revoke-sessions")->assertOk()->assertJsonPath('data.tokens', 2);
        $this->assertSame(0, $faculty->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_SESSIONS_REVOKED', 'entity_id' => $faculty->id]);
        $this->asAdmin()->postJson("/api/admin/users/{$this->admin->id}/revoke-sessions")->assertStatus(422);
    }

    public function test_bulk_actions_need_confirmation_skip_the_admin_and_are_audited(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $ids = [$a->id, $b->id, $this->admin->id];

        $this->asAdmin()->postJson('/api/admin/users/bulk', ['ids' => $ids, 'action' => 'set_role', 'role' => 'DEPARTMENT_HEAD'])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->asAdmin()->postJson('/api/admin/users/bulk', ['ids' => $ids, 'action' => 'deactivate', 'confirm' => true])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->asAdmin()->postJson('/api/admin/users/bulk', ['ids' => $ids, 'action' => 'set_role', 'role' => 'DEPARTMENT_HEAD', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped', [$this->admin->id]);

        $this->assertTrue($a->fresh()->isDepartmentHead());
        $this->assertTrue($this->admin->fresh()->isAdmin());
        $this->assertSame(2, AuditLog::query()->where('action', 'ADMIN_USER_BULK_UPDATED')->count());
    }

    public function test_csv_import_previews_errors_and_creates_only_valid_rows(): void
    {
        User::factory()->create(['email' => 'exists@aust.edu']);
        $csv = "name,email,department,role\n"
            ."Alice,alice@aust.edu,CSE,FACULTY\n"
            ."Bob,exists@aust.edu,CSE,FACULTY\n"
            ."Carol,not-an-email,CSE,FACULTY\n"
            ."Alice Again,ALICE@aust.edu,CSE,FACULTY\n"
            ."Dan,dan@aust.edu,EEE,ADMIN\n"
            ."\"Eve, PhD\",eve@aust.edu,CSE,department_head\n";
        $file = fn () => UploadedFile::fake()->createWithContent('staff.csv', $csv);

        $preview = $this->asAdmin()->post('/api/admin/users/import/preview', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(2, $preview['valid']);
        $this->assertSame(4, $preview['invalid']);
        $this->assertSame(0, User::query()->where('email', 'alice@aust.edu')->count(), 'preview must not write');

        $this->asAdmin()->post('/api/admin/users/import', ['file' => $file(), 'send_invites' => '0'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asAdmin()->post('/api/admin/users/import', ['file' => $file(), 'send_invites' => '0', 'confirm' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.success_count', 2)->assertJsonPath('data.failure_count', 4)->assertJsonPath('data.status', 'completed');

        $this->assertSame('Eve, PhD', User::query()->where('email', 'eve@aust.edu')->value('name'));
        $this->assertSame('DEPARTMENT_HEAD', User::query()->where('email', 'eve@aust.edu')->value('role'));
        $this->assertDatabaseMissing('users', ['email' => 'dan@aust.edu']);
        $this->assertDatabaseHas('admin_operations', ['type' => 'user_import', 'status' => 'completed']);
    }

    // ------------------------------------------------------------------ audit + security

    public function test_failed_sign_ins_are_recorded_as_facts_and_listed_in_the_security_center(): void
    {
        $faculty = User::factory()->create(['email' => 'f@aust.edu', 'password' => 'Password123!']);
        $this->postJson('/api/auth/login', ['email' => 'f@aust.edu', 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'nobody@aust.edu', 'password' => 'wrong'])->assertStatus(401);

        $logs = AuditLog::query()->where('action', 'AUTH_LOGIN_FAILED')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($faculty->id, $logs->firstWhere('entity_id', '!=', null)->entity_id);
        $this->assertStringNotContainsString('nobody@aust.edu', json_encode($logs->pluck('metadata')));

        $this->asAdmin()->getJson('/api/admin/security?days=7')->assertOk()
            ->assertJsonPath('data.facts.failed_sign_ins', 2)
            ->assertJsonPath('data.targeted_accounts.0.user_id', $faculty->id)
            ->assertJsonPath('data.posture.admin_two_factor', false);

        $this->asAdmin()->getJson('/api/admin/audit-logs?action=AUTH_LOGIN_FAILED')->assertOk()->assertJsonPath('meta.total', 2);
        $this->asAdmin()->getJson('/api/admin/audit-logs?search=login failed')->assertOk()->assertJsonPath('meta.total', 2);
    }

    // ------------------------------------------------------------------ health + alerts

    public function test_health_check_records_explicit_states_and_never_guesses_healthy(): void
    {
        config(['mail.default' => 'log', 'broadcasting.default' => 'null', 'queue.default' => 'sync']);
        $this->fakeAi(200);

        $services = collect($this->asAdmin()->postJson('/api/admin/system/health/check')->assertOk()->json('data.services'))->keyBy('service');

        $this->assertSame('healthy', $services['database']['status']);
        $this->assertSame('healthy', $services['ai_service']['status']);
        $this->assertSame('all-MiniLM-L6-v2', $services['ai_service']['detail']['model']);
        $this->assertSame('unconfigured', $services['mail']['status']);
        $this->assertSame('unconfigured', $services['broadcasting']['status']);
        $this->assertSame('unconfigured', $services['queue']['status']);
        $this->assertSame('unknown', $services['scheduler']['status'], 'a manual check is not a scheduler heartbeat');
        $this->assertSame(count(ServiceMonitor::SERVICES), HealthCheckResult::query()->count());
        $this->assertDatabaseHas('admin_operations', ['type' => 'health_check', 'status' => 'completed']);

        $this->asAdmin()->getJson('/api/admin/system/health')->assertOk()->assertJsonPath('data.history.database.checks', 1);

        // The scheduled run writes the heartbeat, after which the scheduler reports healthy
        $this->fakeAi(200, 200);
        $this->artisan('admin:monitor')->assertSuccessful();
        $this->assertSame('healthy', app(ServiceMonitor::class)->check('scheduler')['status']);
    }

    public function test_unreachable_ai_service_is_unavailable_and_raises_one_deduplicated_alert(): void
    {
        Event::fake([\App\Events\SystemAlertRaised::class]);
        $this->fakeAi(503, 503, 200);
        $monitor = app(ServiceMonitor::class);
        $evaluator = app(AlertEvaluator::class);

        $evaluator->evaluate($monitor->checkAll());
        $evaluator->evaluate($monitor->checkAll());

        $alert = AdminAlert::query()->where('dedupe_key', 'service_unavailable:ai_service')->firstOrFail();
        $this->assertSame('open', $alert->status);
        $this->assertSame(2, $alert->occurrences);
        $this->assertSame('critical', $alert->severity);
        $aiNotifications = Event::dispatched(\App\Events\SystemAlertRaised::class, fn ($e) => ($e->data['rule'] ?? null) === 'service_unavailable');
        $this->assertCount(1, $aiNotifications, 'a persisting condition notifies once');
        // No backups exist in the test database, so the stale-backup rule is (correctly) active too
        $this->assertDatabaseHas('admin_alerts', ['rule' => 'backup_stale', 'status' => 'open']);

        // Condition clears → the alert resolves itself
        $evaluator->evaluate($monitor->checkAll());
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_alerts_raised_inside_a_maintenance_window_are_recorded_but_not_notified(): void
    {
        Event::fake([\App\Events\SystemAlertRaised::class]);
        MaintenanceWindow::query()->create(['title' => 'AI upgrade', 'services' => ['ai_service'], 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(), 'suppress_alerts' => true]);
        $this->fakeAi(500);

        app(AlertEvaluator::class)->evaluate(app(ServiceMonitor::class)->checkAll());

        $alert = AdminAlert::query()->where('rule', 'service_unavailable')->firstOrFail();
        $this->assertTrue($alert->suppressed);
        $this->assertNull($alert->notified_at);
        Event::assertNotDispatched(\App\Events\SystemAlertRaised::class, fn ($e) => ($e->data['rule'] ?? null) === 'service_unavailable');
        // The window only covers the AI service: other conditions still notify
        $this->assertFalse(AdminAlert::query()->where('rule', 'backup_stale')->firstOrFail()->suppressed);
    }

    public function test_alert_acknowledge_resolve_and_escalate_to_an_incident(): void
    {
        $alert = AdminAlert::query()->create(['rule' => 'queue_backlog', 'dedupe_key' => 'queue_backlog', 'severity' => 'warning', 'service' => 'queue', 'title' => 'Queue backlog', 'message' => '300 waiting', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->asAdmin()->postJson("/api/admin/alerts/{$alert->id}/acknowledge")->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $incidentId = $this->asAdmin()->postJson("/api/admin/alerts/{$alert->id}/escalate")->assertOk()->json('data.incident_id');
        $this->assertSame($incidentId, $alert->fresh()->incident_id);
        $this->asAdmin()->postJson("/api/admin/alerts/{$alert->id}/resolve", [])->assertStatus(422);
        $this->asAdmin()->postJson("/api/admin/alerts/{$alert->id}/resolve", ['note' => 'Workers restarted'])->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->asAdmin()->getJson("/api/admin/incidents/{$incidentId}")->assertOk()
            ->assertJsonPath('data.alerts.0.id', $alert->id)
            ->assertJsonPath('data.events.1.type', 'alert_linked');
    }

    public function test_incident_lifecycle_requires_a_root_cause_and_keeps_a_timeline(): void
    {
        $id = $this->asAdmin()->postJson('/api/admin/incidents', ['title' => 'Document chat failing', 'category' => 'ai', 'severity' => 'high'])
            ->assertCreated()->assertJsonPath('data.reference', 'INC-0001')->json('data.id');

        $this->asAdmin()->postJson("/api/admin/incidents/{$id}/notes", ['body' => 'Embedding job failing with timeouts.'])->assertCreated();
        $this->asAdmin()->patchJson("/api/admin/incidents/{$id}", ['status' => 'resolved'])->assertStatus(422)->assertJsonValidationErrors('root_cause');
        $this->asAdmin()->patchJson("/api/admin/incidents/{$id}", ['status' => 'resolved', 'root_cause' => 'AI service timeout too low', 'corrective_actions' => 'Raised AI_SERVICE_TIMEOUT'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.root_cause', 'AI service timeout too low');

        $incident = \App\Models\AdminIncident::query()->findOrFail($id);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(['created', 'note', 'status', 'note', 'note'], $incident->events()->pluck('type')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_INCIDENT_UPDATED', 'entity_id' => $id]);
    }

    // ------------------------------------------------------------------ queues

    public function test_failed_jobs_hide_payloads_scrub_exceptions_and_only_retry_safe_jobs(): void
    {
        $insert = fn (string $class, string $exception) => tap((string) Str::uuid(), fn ($uuid) => DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'emails',
            'payload' => json_encode(['displayName' => $class, 'data' => ['command' => 'O:secret-context-with-reset-token']]),
            'exception' => $exception, 'failed_at' => now(),
        ]));
        $emailJob = $insert('App\\Jobs\\SendFacultyLensEmailJob', "RuntimeException: SMTP rejected jane.doe@aust.edu in /var/www/app/Jobs/Send.php:12\n#0 stack");
        $insert('App\\Jobs\\ProcessDocumentJob', "Illuminate\\Database\\QueryException: SQLSTATE[23000]: select * from users where email='x@y.z'\n#0");

        $res = $this->asAdmin()->getJson('/api/admin/queues/failed')->assertOk();
        $body = $res->getContent();
        $this->assertStringNotContainsString('secret-context', $body);
        $this->assertStringNotContainsString('jane.doe@aust.edu', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $rows = collect($res->json('data'))->keyBy('job_name');
        $this->assertFalse($rows['SendFacultyLensEmailJob']['retryable']);
        $this->assertTrue($rows['ProcessDocumentJob']['retryable']);
        $this->assertStringContainsString('[email]', $rows['SendFacultyLensEmailJob']['exception']);

        $this->asAdmin()->postJson("/api/admin/queues/failed/{$emailJob}/retry")->assertStatus(422);
        $this->asAdmin()->deleteJson("/api/admin/queues/failed/{$emailJob}")->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $emailJob]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_JOB_DISMISSED']);
        $this->asAdmin()->getJson('/api/admin/queues')->assertOk()->assertJsonPath('data.failed_total', 1);
    }

    public function test_exception_summary_is_short_and_safe(): void
    {
        $this->assertSame('TimeoutException: AI service timed out', FailedJobInspector::exceptionSummary("App\\Exceptions\\TimeoutException: AI service timed out\n#0 trace"));
        $this->assertSame('No exception recorded.', FailedJobInspector::exceptionSummary(''));
    }

    // ------------------------------------------------------------------ data quality

    public function test_data_quality_scan_detects_issues_without_changing_records_and_revalidates(): void
    {
        $owner = User::factory()->create(['department' => 'CSE']);
        $course = $this->course($owner);
        $assessment = Assessment::query()->create(['course_id' => $course->id, 'title' => 'Midterm', 'total_marks' => 50]);
        $q = Question::query()->create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => 'Explain BFS', 'marks' => 30]);
        Assessment::query()->create(['course_id' => $course->id, 'title' => 'Empty quiz', 'total_marks' => 10]);

        $this->asAdmin()->postJson('/api/admin/data-quality/scan')->assertOk()->assertJsonPath('data.status', 'completed');

        $checks = DataQualityFinding::query()->pluck('check')->all();
        foreach (['assessment_marks_mismatch', 'assessment_without_questions', 'course_without_outcomes', 'course_without_program', 'question_without_outcome'] as $check) {
            $this->assertContains($check, $checks);
        }
        $this->assertSame('50.00', (string) $assessment->fresh()->total_marks, 'scanner must be read-only');
        $mismatch = DataQualityFinding::query()->where('check', 'assessment_marks_mismatch')->firstOrFail();
        $this->assertStringContainsString('30', $mismatch->explanation);

        // Faculty fixes the marks; the re-scan resolves the finding by itself
        $q->update(['marks' => 50]);
        $this->asAdmin()->postJson('/api/admin/data-quality/scan')->assertOk();
        $this->assertSame('resolved', $mismatch->fresh()->status);
        $this->assertTrue($mismatch->fresh()->auto_resolved);

        // Ignoring needs a note; ignored findings stay ignored on re-scan
        $empty = DataQualityFinding::query()->where('check', 'assessment_without_questions')->firstOrFail();
        $this->asAdmin()->patchJson("/api/admin/data-quality/{$empty->id}", ['status' => 'ignored'])->assertStatus(422);
        $this->asAdmin()->patchJson("/api/admin/data-quality/{$empty->id}", ['status' => 'ignored', 'note' => 'Practice quiz'])->assertOk();
        $this->asAdmin()->postJson('/api/admin/data-quality/scan')->assertOk();
        $this->assertSame('ignored', $empty->fresh()->status);
    }

    // ------------------------------------------------------------------ exports

    public function test_exports_are_formula_safe_private_audited_and_expire(): void
    {
        Storage::fake('local');
        User::factory()->create(['name' => '=HYPERLINK("http://evil")', 'email' => 'evil@aust.edu']);

        $op = $this->asAdmin()->postJson('/api/admin/exports', ['dataset' => 'users'])->assertCreated()->json('data');
        $this->assertTrue($op['result_available']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_EXPORT_CREATED']);

        $res = $this->asAdmin()->get("/api/admin/operations/{$op['id']}/download")->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('password', strtolower(explode("\n", $csv)[0]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ADMIN_EXPORT_DOWNLOADED']);

        AdminOperation::query()->whereKey($op['id'])->update(['result_expires_at' => now()->subMinute()]);
        $this->asAdmin()->getJson("/api/admin/operations/{$op['id']}/download")->assertStatus(410);

        $this->asAdmin()->postJson('/api/admin/exports', ['dataset' => 'passwords'])->assertStatus(422);
    }

    public function test_safe_csv_neutralises_formulas_but_keeps_numbers(): void
    {
        $this->assertSame("'=1+2", SafeCsv::cell('=1+2'));
        $this->assertSame("'@SUM(A1)", SafeCsv::cell('@SUM(A1)'));
        $this->assertSame('-42', SafeCsv::cell('-42'));
        $this->assertSame("'-cmd", SafeCsv::cell('-cmd'));
        $this->assertSame('yes', SafeCsv::cell(true));
    }

    // ------------------------------------------------------------------ backups, maintenance, search, governance

    public function test_backup_verification_detects_tampering(): void
    {
        Storage::fake('local');
        $gz = gzencode('CREATE TABLE t (id int);');
        Storage::disk('local')->put('backups/b.sql.gz', $gz);
        $backup = DatabaseBackup::query()->create(['filename' => 'b.sql.gz', 'disk' => 'local', 'path' => 'backups/b.sql.gz', 'size_bytes' => strlen($gz),
            'checksum_sha256' => hash('sha256', $gz), 'status' => 'completed', 'trigger' => 'manual', 'completed_at' => now()]);

        $this->asAdmin()->postJson("/api/admin/backups/{$backup->id}/verify")->assertOk()->assertJsonPath('data.verification_status', 'verified');

        Storage::disk('local')->put('backups/b.sql.gz', gzencode('tampered'));
        $this->asAdmin()->postJson("/api/admin/backups/{$backup->id}/verify")->assertStatus(422)->assertJsonPath('data.verification_status', 'failed');
        $this->asAdmin()->getJson('/api/admin/backups')->assertOk()->assertJsonPath('meta.restore.available_in_panel', false);
    }

    public function test_maintenance_windows_validate_and_can_be_cancelled(): void
    {
        $this->asAdmin()->postJson('/api/admin/maintenance', ['title' => 'Too long', 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addDays(5)->toIso8601String()])->assertStatus(422);
        $this->asAdmin()->postJson('/api/admin/maintenance', ['title' => 'Bad service', 'services' => ['nope'], 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()])->assertStatus(422);

        $id = $this->asAdmin()->postJson('/api/admin/maintenance', ['title' => 'MySQL upgrade', 'services' => ['database'], 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.state', 'scheduled')->json('data.id');
        $this->asAdmin()->postJson("/api/admin/maintenance/{$id}/cancel")->assertOk()->assertJsonPath('data.state', 'cancelled');
    }

    public function test_search_returns_permission_scoped_metadata_only(): void
    {
        $owner = User::factory()->create(['name' => 'Dr. Turing']);
        $this->course($owner, ['course_code' => 'CSE411', 'course_name' => 'Turing Machines']);

        $groups = collect($this->asAdmin()->getJson('/api/admin/search?q=turing')->assertOk()->json('data'))->keyBy('group');
        $this->assertSame('Dr. Turing', $groups['Users']['items'][0]['title']);
        $this->assertStringContainsString('CSE411', $groups['Courses']['items'][0]['title']);
        $this->asAdmin()->getJson('/api/admin/search?q=a')->assertStatus(422);
    }

    public function test_access_institution_ai_environment_and_schedule_views_load(): void
    {
        User::factory()->create(['role' => 'DEPARTMENT_HEAD', 'department' => 'CSE']);

        $this->asAdmin()->getJson('/api/admin/access')->assertOk()
            ->assertJsonPath('data.roles.0.key', 'ADMIN')->assertJsonPath('data.roles.0.assignable', false)
            ->assertJsonCount(2, 'data.privileged');
        $this->asAdmin()->getJson('/api/admin/institution')->assertOk()->assertJsonPath('data.departments.0.name', 'CSE');
        $this->asAdmin()->getJson('/api/admin/ai')->assertOk()->assertJsonPath('data.evaluation_totals.completed', 0)->assertJsonPath('data.service.status', 'unknown');

        $env = $this->asAdmin()->getJson('/api/admin/system/environment')->assertOk();
        $this->assertStringNotContainsString((string) config('app.key'), $env->getContent());
        $this->assertIsBool($env->json('data.configuration.1.configured'));

        $tasks = collect($this->asAdmin()->getJson('/api/admin/system/schedule')->assertOk()->json('data.tasks'))->pluck('command');
        $this->assertTrue($tasks->contains(fn ($c) => str_starts_with($c, 'admin:monitor')));
        $this->assertFalse($tasks->contains(fn ($c) => str_contains($c, 'php')), 'binary paths are stripped');
    }
}
