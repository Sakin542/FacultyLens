<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicEvent;
use App\Models\Assessment;
use App\Models\Course;
use App\Services\CourseAccessService;
use App\Services\Planning\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CalendarController extends Controller
{
    public function __construct(protected CalendarService $calendar, protected CourseAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();
        if ($from->diffInDays($to) > 400) {
            return response()->json(['status' => 'error', 'message' => 'Ask for at most about a year at a time.'], 422);
        }

        return response()->json(['status' => 'success', 'data' => $this->calendar->events($request->user(), $from, $to)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $event = AcademicEvent::query()->create($data + ['user_id' => $request->user()->id]);

        return response()->json(['status' => 'success', 'message' => 'Event added to your calendar.', 'data' => $this->calendar->eventPayload($event->load(['course', 'assessment']))], 201);
    }

    public function update(Request $request, AcademicEvent $event): JsonResponse
    {
        abort_unless($event->user_id === $request->user()->id, 404);
        $data = $this->validated($request, partial: true);
        // Moving the event re-arms its reminders
        if (isset($data['starts_at']) || array_key_exists('reminder_offsets', $data)) {
            $data['reminders_sent'] = [];
        }
        $event->fill($data)->save();

        return response()->json(['status' => 'success', 'message' => 'Event updated.', 'data' => $this->calendar->eventPayload($event->fresh(['course', 'assessment']))]);
    }

    public function destroy(Request $request, AcademicEvent $event): JsonResponse
    {
        abort_unless($event->user_id === $request->user()->id, 404);
        $event->delete();

        return response()->json(['status' => 'success', 'message' => 'Event deleted.']);
    }

    /** Subscribe/import file for Google Calendar, Outlook or Apple Calendar. */
    public function ics(Request $request): Response
    {
        return response($this->calendar->ics($request->user()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="facultylens-calendar.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'type' => [$req, Rule::in(AcademicEvent::TYPES)],
            'starts_at' => [$req, 'date'],
            'ends_at' => array_merge(['sometimes', 'nullable', 'date'], $request->has('starts_at') ? ['after_or_equal:starts_at'] : []),
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'course_id' => ['sometimes', 'nullable', 'integer', 'exists:courses,id'],
            'assessment_id' => ['sometimes', 'nullable', 'integer', 'exists:assessments,id'],
            'reminder_offsets' => ['sometimes', 'nullable', 'array', 'max:5'],
            'reminder_offsets.*' => ['integer', 'min:5', 'max:43200'],
        ]);

        if (!empty($data['course_id'])) {
            abort_unless($this->access->can($request->user(), Course::find($data['course_id']), 'view'), 403, 'You do not have access to that course.');
        }
        if (!empty($data['assessment_id'])) {
            $assessment = Assessment::with('course')->find($data['assessment_id']);
            abort_unless($assessment && $this->access->can($request->user(), $assessment->course, 'view'), 403, 'You do not have access to that assessment.');
            $data['course_id'] ??= $assessment->course_id;
        }
        if (isset($data['reminder_offsets'])) {
            $data['reminder_offsets'] = array_values(array_unique(array_map('intval', $data['reminder_offsets'])));
            rsort($data['reminder_offsets']);
        }

        return $data;
    }
}
