<?php

namespace App\Services\Planning;

use App\Models\AcademicEvent;
use App\Models\Assessment;
use App\Models\User;
use App\Services\CourseAccessService;
use App\Services\Notification\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Academic calendar: the user's own events plus dated assessments in their courses, reminders through the
 * notification system (in-app + e-mail), and an iCalendar (.ics) export for Google/Outlook/Apple calendars.
 */
class CalendarService
{
    public function __construct(protected CourseAccessService $access, protected NotificationService $notifications) {}

    /** @return list<array<string, mixed>> */
    public function events(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $own = AcademicEvent::query()->where('user_id', $user->id)
            ->where('starts_at', '<=', $to)
            ->where(fn ($q) => $q->where('starts_at', '>=', $from)->orWhere('ends_at', '>=', $from))
            ->with(['course:id,course_code,course_name', 'assessment:id,title'])
            ->orderBy('starts_at')->get()
            ->map(fn (AcademicEvent $e) => $this->eventPayload($e));

        // Assessments with a date that are not already on the calendar as an event
        $linked = AcademicEvent::query()->where('user_id', $user->id)->whereNotNull('assessment_id')->pluck('assessment_id')->all();
        $assessments = Assessment::query()
            ->whereIn('course_id', $this->access->accessibleCourseIds($user) ?: [-1])
            ->whereNotNull('assessment_date')
            ->whereBetween('assessment_date', [$from->copy()->startOfDay(), $to])
            ->whereNotIn('id', $linked ?: [-1])
            ->with('course:id,course_code,course_name')->get()
            ->map(fn (Assessment $a) => [
                'id' => 'assessment-'.$a->id,
                'source' => 'assessment',
                'title' => $a->title,
                'type' => in_array(strtolower((string) $a->type), ['quiz', 'assignment'], true) ? (strtolower($a->type) === 'quiz' ? 'quiz' : 'assignment_deadline') : 'exam',
                'starts_at' => Carbon::parse($a->assessment_date)->startOfDay()->toIso8601String(),
                'ends_at' => null,
                'all_day' => true,
                'location' => null,
                'notes' => null,
                'reminder_offsets' => [],
                'course' => $a->course?->only(['id', 'course_code', 'course_name']),
                'assessment' => ['id' => $a->id, 'title' => $a->title],
                'editable' => false,
            ]);

        return $own->concat($assessments)->sortBy('starts_at')->values()->all();
    }

    public function eventPayload(AcademicEvent $e): array
    {
        return [
            'id' => $e->id,
            'source' => 'event',
            'title' => $e->title,
            'type' => $e->type,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'all_day' => $e->all_day,
            'location' => $e->location,
            'notes' => $e->notes,
            'reminder_offsets' => array_values((array) $e->reminder_offsets),
            'course' => $e->course?->only(['id', 'course_code', 'course_name']),
            'assessment' => $e->assessment ? ['id' => $e->assessment->id, 'title' => $e->assessment->title] : null,
            'editable' => true,
        ];
    }

    /**
     * Send every reminder that has come due (offset reached, event not started, not sent before).
     *
     * @return int reminders sent
     */
    public function sendDueReminders(?CarbonInterface $now = null): int
    {
        $now = $now ?? now();
        $maxOffset = 60 * 24 * 30;
        $sent = 0;

        AcademicEvent::query()
            ->whereNotNull('reminder_offsets')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addMinutes($maxOffset))
            ->with(['user', 'course:id,course_code'])
            ->chunkById(200, function ($events) use ($now, &$sent) {
                foreach ($events as $event) {
                    $done = array_map('intval', (array) $event->reminders_sent);
                    $due = array_filter(array_map('intval', (array) $event->reminder_offsets),
                        fn ($offset) => !in_array($offset, $done, true) && $now->greaterThanOrEqualTo($event->starts_at->copy()->subMinutes($offset)));
                    if ($due === [] || !$event->user) {
                        continue;
                    }
                    // Several offsets due at once (e.g. created late): one reminder, all marked sent
                    try {
                        $this->notifications->notify($event->user, 'DEADLINE_REMINDER', [
                            'title' => $this->reminderTitle($event, $now),
                            'message' => $this->reminderMessage($event),
                            'action_url' => '/calendar?date='.$event->starts_at->toDateString(),
                            'entity_type' => 'AcademicEvent',
                            'entity_id' => $event->id,
                            'dedupe_key' => 'DEADLINE_REMINDER:'.$event->id.':'.max($due),
                            'data' => ['event_id' => $event->id, 'starts_at' => $event->starts_at->toIso8601String(), 'type' => $event->type],
                        ]);
                        $event->forceFill(['reminders_sent' => array_values(array_unique(array_merge($done, $due)))])->save();
                        $sent++;
                    } catch (Throwable $e) {
                        Log::warning('Calendar reminder failed: '.$e->getMessage());
                    }
                }
            });

        return $sent;
    }

    protected function reminderTitle(AcademicEvent $event, CarbonInterface $now): string
    {
        $minutes = (int) ceil($now->diffInMinutes($event->starts_at, true));
        $when = $minutes >= 1440 ? (int) round($minutes / 1440).' day(s)' : ($minutes >= 60 ? (int) round($minutes / 60).' hour(s)' : $minutes.' minute(s)');

        return mb_substr("{$event->title} in {$when}", 0, 190);
    }

    protected function reminderMessage(AcademicEvent $event): string
    {
        $type = str_replace('_', ' ', $event->type);
        $course = $event->course ? " for {$event->course->course_code}" : '';
        $at = $event->all_day ? $event->starts_at->format('l, j F Y') : $event->starts_at->format('l, j F Y \a\t H:i');

        return ucfirst($type)."{$course} on {$at}".($event->location ? " ({$event->location})" : '').'.';
    }

    /** RFC 5545 calendar of the user's events and dated assessments (next 12 months, previous month). */
    public function ics(User $user): string
    {
        $esc = fn (?string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        // RFC 5545 §3.1: fold at 75 octets without splitting a UTF-8 character
        $fold = function (string $line): string {
            $out = '';
            $current = '';
            foreach (mb_str_split($line) as $char) {
                if (strlen($current) + strlen($char) > 74) {
                    $out .= $current."\r\n ";
                    $current = '';
                }
                $current .= $char;
            }

            return $out.$current;
        };
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FacultyLens//Academic Calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:FacultyLens'];

        foreach ($this->events($user, now()->subMonth(), now()->addYear()) as $e) {
            $start = Carbon::parse($e['starts_at']);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['id'].'@facultylens';
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($e['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.($e['ends_at'] ? Carbon::parse($e['ends_at'])->addDay()->format('Ymd') : $start->copy()->addDay()->format('Ymd'));
            } else {
                $lines[] = 'DTSTART:'.$start->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.($e['ends_at'] ? Carbon::parse($e['ends_at'])->utc() : $start->copy()->addHour()->utc())->format('Ymd\THis\Z');
            }
            $summary = ($e['course']['course_code'] ?? null) ? "[{$e['course']['course_code']}] {$e['title']}" : $e['title'];
            $lines[] = $fold('SUMMARY:'.$esc($summary));
            $lines[] = 'CATEGORIES:'.strtoupper($e['type']);
            if ($e['location']) {
                $lines[] = $fold('LOCATION:'.$esc($e['location']));
            }
            if ($e['notes']) {
                $lines[] = $fold('DESCRIPTION:'.$esc($e['notes']));
            }
            foreach ($e['reminder_offsets'] as $offset) {
                $lines[] = 'BEGIN:VALARM';
                $lines[] = 'ACTION:DISPLAY';
                $lines[] = $fold('DESCRIPTION:'.$esc($e['title']));
                $lines[] = 'TRIGGER:-PT'.(int) $offset.'M';
                $lines[] = 'END:VALARM';
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }
}
