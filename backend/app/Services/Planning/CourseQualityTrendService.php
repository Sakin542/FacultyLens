<?php

namespace App\Services\Planning;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseAccessService;

/**
 * "Is this course getting better?" Offerings of the same course are linked by course code; each semester's
 * metrics are compared with the previous offering and turned into plain-language insights.
 */
class CourseQualityTrendService
{
    public function __construct(protected CourseMetricsService $metrics, protected CourseAccessService $access) {}

    /** @return list<Course> offerings the user may view, oldest first */
    public function offerings(Course $course, User $user): array
    {
        $code = mb_strtolower(trim((string) $course->course_code));

        return Course::query()->whereRaw('LOWER(TRIM(course_code)) = ?', [$code])->get()
            ->filter(fn (Course $c) => $this->access->can($user, $c, 'view_analysis'))
            ->sortBy(fn (Course $c) => sprintf('%04d-%d-%010d', (int) $c->academic_year, CourseMetricsService::semesterOrder($c->semester), $c->id))
            ->values()->all();
    }

    public function trend(Course $course, User $user): array
    {
        $offerings = array_map(fn (Course $c) => $this->metrics->metrics($c), $this->offerings($course, $user));
        $latest = end($offerings) ?: null;
        $previous = count($offerings) > 1 ? $offerings[count($offerings) - 2] : null;

        return [
            'course_code' => $course->course_code,
            'course_name' => $course->course_name,
            'offerings' => $offerings,
            'insights' => ($latest && $previous) ? $this->insights($previous, $latest) : [],
            'message' => count($offerings) < 2
                ? 'Only one offering of this course is on record. Trends appear once the course is taught again (create a new course with the same code for the next semester).'
                : null,
        ];
    }

    /** @return list<array{metric: string, direction: string, text: string}> */
    protected function insights(array $prev, array $cur): array
    {
        $out = [];
        $compare = function (string $metric, ?float $a, ?float $b, string $label, string $unit, bool $higherIsBetter, float $minChange) use (&$out, $prev, $cur) {
            if ($a === null || $b === null) {
                return;
            }
            $delta = round($b - $a, 2);
            if (abs($delta) < $minChange) {
                $out[] = ['metric' => $metric, 'direction' => 'steady', 'text' => "{$label} held steady at {$b}{$unit} ({$prev['course']['term']} → {$cur['course']['term']})."];

                return;
            }
            $better = ($delta > 0) === $higherIsBetter;
            $verb = $delta > 0 ? 'rose' : 'fell';
            $out[] = [
                'metric' => $metric,
                'direction' => $better ? 'improved' : 'declined',
                'text' => "{$label} {$verb} from {$a}{$unit} to {$b}{$unit} ({$prev['course']['term']} → {$cur['course']['term']}).",
            ];
        };

        $compare('higher_order_percent', $prev['higher_order_percent'], $cur['higher_order_percent'], 'Higher-order questions (Analyze and above)', '% of marks', true, 2);
        $compare('co_coverage_percent', $prev['co_coverage_percent'], $cur['co_coverage_percent'], 'Course outcome coverage', '%', true, 2);
        $compare('co_attained_percent', $prev['co_attained_percent'], $cur['co_attained_percent'], 'Course outcomes attained', '%', true, 2);
        $compare('class_average_percent', $prev['class_average_percent'], $cur['class_average_percent'], 'Class average', '%', true, 2);
        $compare('mean_discrimination', $prev['mean_discrimination'], $cur['mean_discrimination'], 'Average question discrimination', '', true, 0.05);
        $compare('ai_quality_score', $prev['ai_quality_score'], $cur['ai_quality_score'], 'AI assessment quality score', '', true, 2);

        return $out;
    }
}
