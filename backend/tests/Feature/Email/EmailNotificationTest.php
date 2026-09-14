<?php

namespace Tests\Feature\Email;

use App\Events\AssessmentAnalysisCompleted;
use App\Events\AssessmentAnalysisFailed;
use App\Events\ReportGenerated;
use App\Mail\FacultyLensMail;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\EmailDelivery;
use App\Models\Notification;
use App\Notifications\NotificationType;
use App\Services\Email\EmailContentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * FacultyLens event → NotificationService → in-app + e-mail. Both channels are independent: the e-mail is a
 * FacultyLensMail with the type's template, sent to the resolved recipient, and tracked in email_deliveries.
 */
class EmailNotificationTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['notifications.queue.enabled' => false]);
    }

    public function test_notification_with_email_enabled_creates_in_app_row_and_sends_templated_email(): void
    {
        $user = $this->faculty(['name' => 'Dr. Ada']);
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $notification = $this->notify($user);

        $this->assertInstanceOf(Notification::class, $notification);
        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->template === 'report-ready'
                && $mail->subjectLine === 'FacultyLens — Your Report Is Ready'
                && $mail->context['recipient_name'] === 'Dr. Ada'
                && $mail->context['report_type'] === 'Course Outcome Attainment'
                && $mail->context['action_url'] === rtrim(config('email.frontend_url'), '/') . '/reports/42';
        });

        $delivery = $this->lastDelivery();
        $this->assertSame(EmailDelivery::SENT, $delivery->status);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame('REPORT_GENERATED', $delivery->type);
        $this->assertSame('REPORT', $delivery->category);
        $this->assertSame($notification->id, $delivery->notification_id, 'delivery is linked to its in-app row');
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMAIL_SENT', 'entity_type' => 'EmailDelivery', 'entity_id' => $delivery->id]);
    }

    public function test_email_disabled_still_creates_in_app_notification(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED, false);

        $this->assertNotNull($this->notify($user));
        Mail::assertNothingSent();
        $this->assertSame(0, EmailDelivery::count());
    }

    public function test_in_app_disabled_but_email_enabled_still_sends_email(): void
    {
        $user = $this->faculty();
        \App\Models\NotificationPreference::updateOrCreate(['user_id' => $user->id, 'notification_type' => NotificationType::REPORT_GENERATED], ['in_app_enabled' => false, 'email_enabled' => true]);

        $this->assertNull($this->notify($user));
        $this->assertSame(0, Notification::count());
        Mail::assertSent(FacultyLensMail::class, 1);
        $this->assertSame(EmailDelivery::SENT, $this->lastDelivery()->status);
        $this->assertNull($this->lastDelivery()->notification_id);
    }

    public function test_every_registered_type_maps_to_an_existing_template_and_subject(): void
    {
        $resolver = app(EmailContentResolver::class);
        foreach (NotificationType::all() as $type) {
            $template = $resolver->templateFor($type);
            $this->assertTrue(view()->exists("emails.{$template}"), "$type → $template");
            $this->assertStringStartsWith('FacultyLens — ', $resolver->subjectFor($type));
        }
        $this->assertSame('assessment-analysis-completed', $resolver->templateFor('AI_ANALYSIS_COMPLETED'));
        $this->assertSame('assessment-analysis-failed', $resolver->templateFor('AI_ANALYSIS_FAILED'));
        $this->assertSame('collaboration-invitation', $resolver->templateFor('COLLABORATION_INVITATION'));
        $this->assertSame('review-assigned', $resolver->templateFor('REVIEW_ASSIGNED'));
        $this->assertSame('rubric-generated', $resolver->templateFor('RUBRIC_GENERATED'));
        $this->assertSame('question-generation-completed', $resolver->templateFor('QUESTION_GENERATION_COMPLETED'));
        $this->assertSame('learning-gap-detected', $resolver->templateFor('LEARNING_GAP_DETECTED'));
        $this->assertSame('grading-review-required', $resolver->templateFor('INTER_GRADER_REVIEW_REQUIRED'));
        $this->assertSame('performance-analysis-ready', $resolver->templateFor('PERFORMANCE_ANALYSIS_COMPLETED'));
        $this->assertSame('assessment-version', $resolver->templateFor('ASSESSMENT_VERSION_FINALIZED'));
        $this->assertSame('security-alert', $resolver->templateFor('SECURITY_ALERT'));
        $this->assertSame('notification', $resolver->templateFor('FACULTY_FEEDBACK_RECEIVED'));
    }

    public function test_analysis_completed_event_emails_the_assessment_owner_with_names_resolved(): void
    {
        $owner = $this->faculty();
        $course = Course::create(['user_id' => $owner->id, 'course_code' => 'CSE301', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        $report = AnalysisReport::create(['assessment_id' => $assessment->id, 'analysis_version' => 1, 'is_current' => true, 'analysis_status' => 'completed', 'analyzed_at' => now()]);

        event(new AssessmentAnalysisCompleted($report));

        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && $mail->template === 'assessment-analysis-completed'
                && $mail->context['assessment_name'] === 'Midterm Examination'
                && $mail->context['course_name'] === 'CSE301 Database Systems'
                && str_contains($mail->renderHtml(), 'View Analysis')
                && str_contains($mail->renderHtml(), 'Midterm Examination');
        });
        $this->assertSame('AI_ANALYSIS_COMPLETED', $this->lastDelivery()->type);
        $this->assertSame(EmailDelivery::SENT, $this->lastDelivery()->status);
    }

    public function test_analysis_failed_event_never_uses_the_success_template(): void
    {
        $owner = $this->faculty();
        $course = Course::create(['user_id' => $owner->id, 'course_code' => 'CSE301', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Quiz 1', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $report = AnalysisReport::create(['assessment_id' => $assessment->id, 'analysis_version' => 1, 'is_current' => true, 'analysis_status' => 'failed', 'processing_error' => 'AI service timeout']);

        event(new AssessmentAnalysisFailed($report));

        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $mail) {
            $html = $mail->renderHtml();

            return $mail->template === 'assessment-analysis-failed'
                && $mail->subjectLine === 'FacultyLens — Assessment Analysis Failed'
                && str_contains($html, 'No academic data was automatically changed.')
                && str_contains($html, 'Retry Analysis')
                && !str_contains($html, 'analysis is ready for review')
                && !str_contains($html, 'AI service timeout');
        });
        Mail::assertNotSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'assessment-analysis-completed');
    }

    public function test_report_generated_event_emails_only_the_requester(): void
    {
        $requester = $this->faculty();
        $other = $this->faculty();
        $report = \App\Models\InstitutionalReport::create([
            'report_uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $requester->id, 'report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT',
            'filters' => [], 'title' => 'Outcome report', 'format' => 'PDF', 'status' => 'COMPLETED', 'is_async' => true, 'contains_student_data' => false, 'record_count' => 1,
        ]);

        event(new ReportGenerated($report));

        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->hasTo($requester->email) && $m->template === 'report-ready');
        Mail::assertNotSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->hasTo($other->email));
        $html = Mail::sent(FacultyLensMail::class)->first()->renderHtml();
        $this->assertStringContainsString('reports are never attached to email', $html);
    }

    public function test_email_failure_never_breaks_the_notification_or_the_caller(): void
    {
        // real (array-transport) mailer so the template actually renders inside the job
        Mail::swap(new \Illuminate\Mail\MailManager($this->app));
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);
        $this->app->bind(EmailContentResolver::class, fn () => new class extends EmailContentResolver {
            public function templateFor(string $type): string
            {
                return 'does-not-exist';
            }
        });

        $notification = $this->notify($user);

        $this->assertNotNull($notification, 'in-app write unaffected');
        $delivery = $this->lastDelivery();
        $this->assertSame(EmailDelivery::FAILED, $delivery->status);
        $this->assertSame('TEMPLATE_RENDER_FAILED', $delivery->error_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMAIL_FAILED', 'entity_id' => $delivery->id]);
    }
}
