<?php

namespace App\Services\Email;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Support\Str;

/**
 * Chooses the template + subject for an e-mail type and builds the (sanitised) template context.
 *
 * Notification payloads carry identifiers, not names; the resolver looks up the few human labels a template needs
 * (assessment title, course name) at render time so listeners stay unchanged. Anything matching a forbidden key is
 * dropped, and every link is built from the configured frontend origin — never from user input.
 */
class EmailContentResolver
{
    public const GENERIC_TEMPLATE = 'notification';

    public function templateFor(string $type): string
    {
        $template = (string) config("email.templates.{$type}", self::GENERIC_TEMPLATE);

        return view()->exists("emails.{$template}") ? $template : self::GENERIC_TEMPLATE;
    }

    public function subjectFor(string $type, ?string $fallbackTitle = null): string
    {
        $subject = config("email.subjects.{$type}");
        if (!is_string($subject) || trim($subject) === '') {
            $subject = trim((string) $fallbackTitle) !== '' ? trim((string) $fallbackTitle) : Str::headline(strtolower($type));
        }
        // header injection defence: a subject is a single line
        $subject = preg_replace('/[\r\n\t]+/', ' ', $subject) ?? $subject;

        return Str::limit((string) config('email.brand.subject_prefix', '') . $subject, 180, '');
    }

    public function categoryFor(string $type): ?string
    {
        return NotificationType::isValid($type) ? NotificationType::category($type) : null;
    }

    /** @return list<string> every template name known to the system (for previews) */
    public function templates(): array
    {
        return array_values(array_unique(array_merge(array_values((array) config('email.templates', [])), [self::GENERIC_TEMPLATE])));
    }

    /**
     * Template context for a notification payload as produced by NotificationService::buildPayload().
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function contextForNotification(array $payload, ?User $recipient = null): array
    {
        $data = $this->sanitize((array) ($payload['data'] ?? []));
        $type = (string) ($payload['type'] ?? '');

        $context = [
            'type' => $type,
            'category' => $payload['category'] ?? $this->categoryFor($type),
            'severity' => $payload['severity'] ?? null,
            'recipient_name' => $recipient?->name,
            'title' => (string) ($payload['title'] ?? ''),
            'message' => (string) ($payload['message'] ?? ''),
            'action_url' => $this->absoluteUrl($payload['action_url'] ?? null),
            'action_label' => $this->cleanLabel($data['action_label'] ?? null),
            'data' => $data,
        ];

        $context += $this->resolveNames($data);

        return $context;
    }

    /**
     * Base context shared by every template (brand, footer links). Merged last so templates can rely on it.
     *
     * @return array<string, mixed>
     */
    public function baseContext(): array
    {
        $frontend = rtrim((string) config('email.frontend_url'), '/');

        return [
            'brand_name' => (string) config('email.brand.name', 'FacultyLens'),
            'brand_tagline' => (string) config('email.brand.tagline', ''),
            'app_url' => $frontend,
            'action_url' => $frontend . '/dashboard',
            'preferences_url' => $frontend . '/settings',
            'logo_src' => 'cid:' . \App\Mail\FacultyLensMail::LOGO_CID,
            'year' => now()->year,
        ];
    }

    /**
     * Fake data for the development preview endpoint. Never contains real users, students or tokens.
     *
     * @return array<string, mixed>
     */
    public function previewContext(string $template): array
    {
        $common = [
            'recipient_name' => 'Dr. Preview Faculty',
            'assessment_name' => 'Midterm Examination',
            'course_name' => 'CSE-301 Database Systems',
            'course_code' => 'CSE-301',
            'action_url' => rtrim((string) config('email.frontend_url'), '/') . '/dashboard',
            'data' => [],
        ];

        return match ($template) {
            'assessment-analysis-completed' => $common + ['type' => 'AI_ANALYSIS_COMPLETED', 'title' => 'Assessment analysis completed', 'message' => 'Your assessment analysis is ready for review.', 'action_label' => 'View Analysis'],
            'assessment-analysis-failed' => $common + ['type' => 'AI_ANALYSIS_FAILED', 'title' => 'Assessment analysis failed', 'message' => 'The requested AI assessment analysis could not be completed.', 'action_label' => 'Retry Analysis'],
            'recommendations-created' => $common + ['type' => 'AI_RECOMMENDATION_CREATED', 'title' => 'New assessment recommendations', 'message' => 'FacultyLens identified areas that may require faculty review.', 'action_label' => 'Review Recommendations', 'data' => ['recommendation_count' => 3]],
            'rubric-generated' => $common + ['type' => 'RUBRIC_GENERATED', 'title' => 'Rubric draft ready', 'message' => 'A draft rubric has been generated for your question.', 'action_label' => 'Review Rubric', 'data' => ['version' => 1, 'question_id' => 12]],
            'question-generation-completed' => $common + ['type' => 'QUESTION_GENERATION_COMPLETED', 'title' => 'Question drafts ready', 'message' => 'Your AI-generated question drafts are ready.', 'action_label' => 'Review Drafts', 'data' => ['draft_count' => 5]],
            'assessment-version' => $common + ['type' => 'ASSESSMENT_VERSION_FINALIZED', 'title' => 'Assessment version finalized', 'message' => 'Assessment version v2 has been finalized and is now locked for editing.', 'action_label' => 'Open Version', 'version_label' => 'v2', 'data' => ['version_label' => 'v2', 'status' => 'FINALIZED']],
            'collaboration-invitation' => $common + ['type' => 'COLLABORATION_INVITATION', 'title' => 'Collaboration invitation', 'message' => 'Dr. Inviter invited you to collaborate.', 'action_label' => 'View Invitation', 'inviter_name' => 'Dr. A. Inviter', 'role' => 'EDITOR', 'data' => ['role' => 'EDITOR', 'actor_name' => 'Dr. A. Inviter']],
            'review-assigned' => $common + ['type' => 'REVIEW_ASSIGNED', 'title' => 'Review assigned', 'message' => 'You have been assigned to review an assessment version.', 'action_label' => 'Open Review', 'version_label' => 'v3'],
            'grading-review-required' => $common + ['type' => 'INTER_GRADER_REVIEW_REQUIRED', 'title' => 'Grading consistency review recommended', 'message' => 'FacultyLens detected variation between faculty grading results that may require review.', 'action_label' => 'Review Grading'],
            'performance-analysis-ready' => $common + ['type' => 'PERFORMANCE_ANALYSIS_COMPLETED', 'title' => 'Performance analysis ready', 'message' => 'Student performance analysis is ready for review.', 'action_label' => 'Open Analysis'],
            'learning-gap-detected' => $common + ['type' => 'LEARNING_GAP_DETECTED', 'title' => 'Learning outcome review recommended', 'message' => 'FacultyLens identified a learning-outcome performance gap that may require review.', 'action_label' => 'Review Gaps', 'data' => ['gap_count' => 2]],
            'report-ready' => $common + ['type' => 'REPORT_GENERATED', 'title' => 'Your report is ready', 'message' => 'Your requested report has been generated successfully.', 'action_label' => 'View Report', 'report_type' => 'Course Outcome Attainment', 'data' => ['report_type' => 'COURSE_OUTCOME_ATTAINMENT', 'format' => 'PDF']],
            'security-alert' => $common + ['type' => 'SECURITY_ALERT', 'title' => 'Repeated failed sign-in attempts', 'message' => 'Several failed sign-in attempts were made on your FacultyLens account. If this was not you, reset your password.', 'action_label' => 'Review Account'],
            'reset-password' => $common + ['type' => 'PASSWORD_RESET', 'reset_url' => rtrim((string) config('email.frontend_url'), '/') . '/reset-password?token=PREVIEW-ONLY&email=preview%40example.edu', 'expires_minutes' => (int) config('auth.passwords.users.expire', 60)],
            'test-email' => $common + ['type' => 'TEST_EMAIL', 'requested_by' => 'Dr. Preview Faculty', 'environment' => (string) config('app.env'), 'sent_at' => now()->toDayDateTimeString(), 'delivery_id' => 0],
            default => $common + ['type' => 'SYSTEM_ALERT', 'title' => 'System notice', 'message' => 'This is a generic FacultyLens notification e-mail.', 'action_label' => 'Open FacultyLens'],
        };
    }

    /** App-relative path → absolute link on the configured frontend origin. Anything else yields the app root. */
    public function absoluteUrl(?string $path): string
    {
        $frontend = rtrim((string) config('email.frontend_url'), '/');
        if ($path === null || trim($path) === '') {
            return $frontend . '/dashboard';
        }
        $path = trim($path);
        if (str_starts_with($path, $frontend . '/')) {
            return $path;
        }
        if (str_starts_with($path, '/') && !str_starts_with($path, '//')) {
            return $frontend . $path;
        }

        return $frontend . '/dashboard';
    }

    /** @param array<string, mixed> $data */
    protected function resolveNames(array $data): array
    {
        $out = [];
        if (!empty($data['course_name'])) {
            $out['course_name'] = trim((string) (($data['course_code'] ?? '') !== '' ? $data['course_code'] . ' ' : '') . $data['course_name']);
        }
        if (!empty($data['assessment_id']) && is_numeric($data['assessment_id'])) {
            $assessment = Assessment::query()->select(['id', 'title', 'course_id'])->find((int) $data['assessment_id']);
            if ($assessment) {
                $out['assessment_name'] = (string) $assessment->title;
                if (!isset($out['course_name']) && $assessment->course_id) {
                    $course = Course::query()->select(['id', 'course_code', 'course_name'])->find($assessment->course_id);
                    if ($course) {
                        $out['course_name'] = trim($course->course_code . ' ' . $course->course_name);
                    }
                }
            }
        } elseif (!isset($out['course_name']) && !empty($data['course_id']) && is_numeric($data['course_id'])) {
            $course = Course::query()->select(['id', 'course_code', 'course_name'])->find((int) $data['course_id']);
            if ($course) {
                $out['course_name'] = trim($course->course_code . ' ' . $course->course_name);
            }
        }
        if (!empty($data['actor_name'])) {
            $out['inviter_name'] = (string) $data['actor_name'];
        }
        if (!empty($data['role'])) {
            $out['role'] = Str::headline(strtolower((string) $data['role']));
        }
        if (!empty($data['report_type'])) {
            $out['report_type'] = Str::headline(strtolower((string) $data['report_type']));
        }
        if (!empty($data['version_label'])) {
            $out['version_label'] = (string) $data['version_label'];
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    protected function sanitize(array $data): array
    {
        $forbidden = array_map('strtolower', (array) config('email.forbidden_context_keys', []));
        $clean = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($forbidden as $f) {
                if ($lower === $f || str_contains($lower, $f)) {
                    continue 2;
                }
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? Str::limit($value, 300, '') : $value;
            }
        }

        return $clean;
    }

    protected function cleanLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : Str::limit(preg_replace('/\s+/', ' ', $label) ?? $label, 40, '');
    }
}
