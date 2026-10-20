<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\FeedbackComment;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\StudentAnswer;
use Illuminate\Database\Seeder;

/**
 * Development fixtures for the faculty assessment tools (exam paper builder, item analysis, plagiarism check,
 * comment bank). Idempotent: safe to run repeatedly on top of DevelopmentSeeder. Synthetic content only.
 */
class FacultyToolsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::query()->where('course_code', 'CSE311')->first();
        if (!$course) {
            $this->command?->warn('CSE311 not found; run DevelopmentSeeder first.');

            return;
        }
        $ownerId = $course->user_id;

        // ---- Course question bank: labelled by Bloom level and difficulty so blueprints can be filled
        $bank = [
            ['Define a relational schema and give one example relation with its attributes.', 'Remember', 'easy', 5],
            ['List the ACID properties of a transaction.', 'Remember', 'easy', 4],
            ['State the difference between DDL and DML with one SQL statement for each.', 'Remember', 'easy', 4],
            ['Explain why a foreign key constraint protects referential integrity.', 'Understand', 'easy', 5],
            ['Describe how a clustered index differs from a non-clustered index.', 'Understand', 'medium', 6],
            ['Explain the lost update problem with a two-transaction schedule.', 'Understand', 'medium', 6],
            ['Explain what a view is and give two reasons to use one.', 'Understand', 'easy', 5],
            ['Write an SQL query that lists each department with the number of employees earning above 50000.', 'Apply', 'medium', 8],
            ['Normalize the relation Orders(order_id, customer, customer_city, item, qty) to 3NF.', 'Apply', 'medium', 10],
            ['Insert the keys 8, 3, 12, 5 and 17 into an empty B+ tree of order 3 and draw each step.', 'Apply', 'hard', 10],
            ['Compute the number of disk reads needed to find a record among 1000000 rows using a B+ tree with fan-out 100.', 'Apply', 'hard', 8],
            ['Analyze whether the schedule r1(A) w2(A) w1(A) c1 c2 is conflict serializable and justify with a precedence graph.', 'Analyze', 'hard', 10],
            ['Compare nested-loop join and hash join for joining a 10000-row table with a 200-row table.', 'Analyze', 'medium', 8],
            ['Analyze the query plan of a slow SELECT and identify the missing index.', 'Analyze', 'hard', 10],
            ['Evaluate whether denormalizing the Orders table is justified for a read-heavy reporting system.', 'Evaluate', 'hard', 10],
            ['Critically evaluate two isolation levels for a banking transfer service.', 'Evaluate', 'medium', 8],
            ['Design an ER diagram for a university library and map it to relations.', 'Create', 'hard', 15],
            ['Design a stored procedure that transfers money between accounts safely.', 'Create', 'hard', 12],
        ];
        foreach ($bank as [$text, $bloom, $difficulty, $marks]) {
            PreviousQuestion::query()->firstOrCreate(
                ['course_id' => $course->id, 'question_text' => $text],
                ['user_id' => $ownerId, 'question_type' => 'descriptive', 'marks' => $marks, 'difficulty_level' => $difficulty, 'cognitive_level' => $bloom, 'source' => 'Question bank', 'source_year' => 2025],
            );
        }

        // ---- Realistic answers for the midterm (replaces only the placeholder text). Students 3 and 4 share a copied Q1 answer.
        $midterm = Assessment::query()->where('course_id', $course->id)->orderBy('id')->first();
        $q1Answers = [
            'A primary key uniquely identifies each row, for example student_id in Student(student_id, name). A candidate key is any minimal set of attributes that could serve as the primary key, such as email. A foreign key in Enrollment(student_id, course_id) references Student so every enrollment points to a real student.',
            'Candidate keys are all minimal unique identifiers; we choose one of them as the primary key. A foreign key is an attribute in one table that refers to the primary key of another table, which keeps references consistent when rows are deleted or updated.',
            'The primary key is chosen from the candidate keys and must be unique and not null. In Employee(emp_id, nid, dept_id) both emp_id and nid are candidate keys. dept_id is a foreign key that must match an existing Department row, otherwise the insert is rejected.',
            'The primary key is chosen from the candidate keys and must be unique and not null. In Employee(emp_id, nid, dept_id) both emp_id and nid are candidate keys. The dept_id column is a foreign key that must match an existing Department row otherwise the insert is rejected by the DBMS.',
            'Keys are used to find rows. Primary key is the main one and foreign key connects tables together in the database so joins can work properly between them.',
            'A key identifies a record. Foreign keys are keys from another table. I think candidate keys are the keys that are candidates for being used in an index on the table.',
        ];
        // Independently written answers for Q2-Q4 (one per student), so only the Q1 pair looks copied
        $others = [
            2 => [
                'Since A determines B and C, and B determines D, A is a key of ABCD. CD->E is not covered, so I create R1(A,B,C), R2(B,D) and R3(C,D,E). Each dependency lives in one relation and the shared attributes are keys, which makes the join lossless.',
                'Minimal cover: A->B, A->C, B->D, CD->E. One relation per FD gives AB, AC, BD, CDE; merging the ones with key A yields ABC. Checking with the chase table, a row of all distinguished values appears, proving the decomposition is lossless.',
                'The candidate key is A plus whatever determines E; I used the synthesis algorithm and got three schemas, then added a schema containing a key of R to guarantee losslessness.',
                'R is not in 3NF because B->D is transitive. Split out BD, then CD->E becomes its own table CDE, leaving ABC. The intersection ABC ∩ BD = B is a key of BD, so this split is lossless.',
                'I decomposed R into ABCD and CDE. I am not fully sure about the proof but the common attributes C and D should make it work.',
                'Third normal form removes partial and transitive dependencies. The relation should be split into smaller tables.',
            ],
            3 => [
                'Order 4 allows 3 keys per node. After 10, 20, 5 the root leaf is [5,10,20]; inserting 15 overflows, so it splits into [5,10] and [15,20] with 15 copied up. 30 goes right, then 25 overflows [15,20,30], splitting into [15,20] and [25,30] and adding 25 to the root [15,25].',
                'Leaves keep all keys and are linked. First split happens at the 4th insert, promoting 15. The final tree has root [15, 25] and three leaves [5,10], [15,20], [25,30].',
                'I inserted keys one by one: 10, 20, 5 fit; 15 forces a split; middle key goes up. Then 30, then 25 which forces another split. The tree height stays 2 for all six keys.',
                'A B+ tree stores data only in the leaves. Splitting moves the median to the parent. My final root contains 20 with leaves [5,10,15] and [20,25,30].',
                'B+ trees are balanced trees used for indexing in databases and they split when full.',
                'Root: 15. Left [5,10], right [15,20,25,30].',
            ],
            4 => [
                'SELECT dept, name, score FROM (SELECT dept, name, score, DENSE_RANK() OVER (PARTITION BY dept ORDER BY score DESC) AS rnk FROM students) ranked WHERE rnk <= 3 ORDER BY dept, rnk;',
                'WITH ranked AS (SELECT s.*, DENSE_RANK() OVER (PARTITION BY department_id ORDER BY total DESC) r FROM student_scores s) SELECT * FROM ranked WHERE r <= 3;',
                'I would use DENSE_RANK so ties share a rank, partition by department and keep rows whose rank is at most three, wrapping the window in a subquery because WHERE cannot see it.',
                'SELECT TOP 3 name FROM students ORDER BY score DESC GROUP BY department -- this gives the best three per department.',
                'Use ROW_NUMBER over department ordered by score and filter number less than four in an outer query.',
                'SELECT * FROM students WHERE score > 80 ORDER BY score DESC LIMIT 3;',
            ],
        ];
        // Marks per student (rows) per question (cols). Q1-Q3 separate strong from weak students; Q4 does not,
        // which item analysis should flag (weaker students scored better on it).
        $marks = [
            [9.0, 14.0, 14.5, 3.0],
            [8.5, 13.0, 12.0, 4.0],
            [7.0, 9.0, 11.0, 6.0],
            [7.0, 8.0, 7.5, 7.0],
            [4.0, 4.5, 6.0, 8.5],
            [2.5, 2.0, 3.0, 9.5],
        ];
        if ($midterm) {
            $submissions = \App\Models\StudentSubmission::query()->where('assessment_id', $midterm->id)
                ->with(['answers.question:id,question_number'])->orderBy('id')->get();
            foreach ($submissions->values() as $index => $submission) {
                if ($index > 5) {
                    break;
                }
                foreach ($submission->answers as $answer) {
                    $n = (int) $answer->question?->question_number;
                    if ($n < 1 || $n > 4) {
                        continue;
                    }
                    $answer->forceFill([
                        'answer_text' => $n === 1 ? $q1Answers[$index] : $others[$n][$index],
                        'awarded_marks' => $marks[$index][$n - 1],
                    ])->saveQuietly();
                }
                $submission->forceFill(['awarded_marks' => array_sum($marks[$index])])->saveQuietly();
            }
        }

        // ---- A starter comment bank for the course owner
        $lo = LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->first();
        foreach ([
            ['Clear definitions', 'Clear and precise definitions with a well-chosen example.', 'positive', 'Accuracy'],
            ['Add an example', 'Good explanation. Add a concrete schema or SQL example to show you can apply it.', 'improvement', 'Use of examples'],
            ['Show your working', 'The final answer is correct, but show each step so partial credit can be given if something goes wrong.', 'improvement', 'Working'],
            ['Check the FDs', 'Re-check which functional dependencies hold in each decomposed relation; one dependency is lost.', 'improvement', 'Correctness'],
        ] as [$title, $body, $tone, $criterion]) {
            FeedbackComment::query()->firstOrCreate(
                ['user_id' => $ownerId, 'title' => $title],
                ['body' => $body, 'tone' => $tone, 'criterion' => $criterion, 'course_id' => $lo ? $course->id : null],
            );
        }

        // Performance analysis reflects the marks above (CO attainment feeds OBE and quality trends)
        $owner = \App\Models\User::find($ownerId);
        if ($midterm && $owner) {
            app(\App\Services\StudentPerformanceService::class)->request($midterm, $owner, true);
        }

        $this->planningData($course, $owner);

        $this->command?->info('Faculty tools demo data ready for CSE311.');
    }

    /** Department, earlier offering, program outcomes and calendar fixtures for the planning features. */
    protected function planningData(Course $course, ?\App\Models\User $owner): void
    {
        if (!$owner) {
            return;
        }
        $department = $owner->department ?: 'Computer Science & Engineering';
        $owner->forceFill(['department' => $department])->save();
        $course->forceFill(['contact_hours_per_week' => 4.5, 'status' => 'active'])->save();

        $hash = \Illuminate\Support\Facades\Hash::make('password123');
        \App\Models\User::query()->firstOrCreate(['email' => 'head@example.com'], ['name' => 'Prof. Ayesha Rahman', 'department' => $department, 'designation' => 'Professor & Head', 'password' => $hash])
            ->forceFill(['role' => 'DEPARTMENT_HEAD'])->save();
        $colleague = \App\Models\User::query()->firstOrCreate(['email' => 'faculty2@example.com'], ['name' => 'Dr. Tanvir Hasan', 'department' => $department, 'designation' => 'Assistant Professor', 'password' => $hash]);
        Course::query()->firstOrCreate(['user_id' => $colleague->id, 'course_code' => 'CSE101'], ['course_name' => 'Structured Programming', 'semester' => 'Spring', 'academic_year' => $course->academic_year, 'credits' => 3, 'status' => 'active']);

        // Earlier offering of the same course (Fall of the previous year): lower-order questions, two COs untested
        $year = (int) $course->academic_year ?: (int) date('Y');
        $fall = Course::query()->firstOrCreate(
            ['user_id' => $owner->id, 'course_code' => $course->course_code, 'semester' => 'Fall', 'academic_year' => (string) ($year - 1)],
            ['course_name' => $course->course_name, 'description' => $course->description, 'credits' => $course->credits, 'status' => 'completed'],
        );
        $los = [];
        foreach (LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get() as $lo) {
            $los[$lo->code] = LearningOutcome::query()->firstOrCreate(['course_id' => $fall->id, 'code' => $lo->code], ['description' => $lo->description, 'cognitive_level' => $lo->cognitive_level, 'sort_order' => $lo->sort_order]);
        }
        $final = Assessment::query()->firstOrCreate(['course_id' => $fall->id, 'title' => 'Final Examination - Fall '.($year - 1)], ['type' => 'final', 'total_marks' => 40, 'status' => 'completed', 'duration_minutes' => 120, 'assessment_date' => ($year - 1).'-12-15']);
        if ($final->questions()->count() === 0 && count($los) >= 2) {
            $spec = [
                ['Define a primary key and a foreign key.', 'Remember', 'easy', 10, 'LO1'],
                ['List the normal forms in order.', 'Remember', 'easy', 10, 'LO2'],
                ['Explain why indexes speed up queries.', 'Understand', 'medium', 10, 'LO1'],
                ['Describe what a transaction is.', 'Understand', 'medium', 10, 'LO2'],
            ];
            foreach ($spec as $i => [$text, $bloom, $diff, $marks, $lo]) {
                \App\Models\Question::query()->create(['assessment_id' => $final->id, 'question_number' => $i + 1, 'question_text' => $text, 'question_type' => 'descriptive', 'marks' => $marks, 'difficulty_level' => $diff, 'cognitive_level' => $bloom, 'learning_outcome_id' => $los[$lo]->id ?? null]);
            }
            // Graded work must reference a finalized paper version (as in DevelopmentSeeder)
            $versions = app(\App\Services\AssessmentVersionService::class);
            $version = $versions->finalizeVersion($owner, $versions->createVersion($owner, $final, ['change_summary' => 'Fall final paper'])->fresh());
            $marksTable = [[8, 7, 6, 5], [7, 6, 5, 5], [6, 5, 5, 4], [5, 5, 4, 3], [4, 3, 3, 2], [3, 2, 2, 2]];
            $students = \App\Models\Student::query()->where('created_by', $owner->id)->orderBy('id')->take(6)->get();
            foreach ($students as $s => $student) {
                $sub = \App\Models\StudentSubmission::query()->create(['assessment_id' => $final->id, 'assessment_version_id' => $version->id, 'student_id' => $student->id, 'submission_identifier' => 'FALL-'.($s + 1), 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => now()->subYear(), 'total_marks' => 40, 'awarded_marks' => array_sum($marksTable[$s])]);
                foreach ($final->questions()->orderBy('id')->get() as $q => $question) {
                    StudentAnswer::query()->create(['student_submission_id' => $sub->id, 'question_id' => $question->id, 'answer_type' => 'TEXT', 'answer_text' => 'Answer recorded for the Fall offering.', 'awarded_marks' => $marksTable[$s][$q], 'answer_status' => 'REVIEWED']);
                }
            }
            app(\App\Services\StudentPerformanceService::class)->request($final, $owner, true);
        }

        // Program outcomes and the CO -> PO mapping of the current offering
        $program = \App\Models\Program::query()->firstOrCreate(['code' => 'BSC-CSE'], ['name' => 'B.Sc. in Computer Science & Engineering', 'department' => $department, 'status' => 'active', 'created_by' => $owner->id]);
        $pos = [];
        // Washington Accord graduate attributes (wording abridged)
        foreach ([
            ['PO1', 'Engineering knowledge', 'Apply knowledge of mathematics, science, computing fundamentals and a specialisation to the solution of complex computing problems.'],
            ['PO2', 'Problem analysis', 'Identify, formulate, research and analyse complex computing problems, reaching substantiated conclusions using principles of mathematics and computing.'],
            ['PO3', 'Design and development of solutions', 'Design and develop software systems, databases, components or processes that meet specified needs with appropriate consideration for constraints.'],
            ['PO5', 'Modern tool usage', 'Create, select and apply appropriate techniques, resources and modern computing tools such as database systems, query languages and programming environments.'],
        ] as $i => [$code, $title, $description]) {
            $pos[$code] = \App\Models\ProgramOutcome::query()->updateOrCreate(['program_id' => $program->id, 'code' => $code], ['title' => $title, 'description' => $description, 'sort_order' => $i + 1, 'status' => 'active']);
        }
        $course->forceFill(['program_id' => $program->id])->save();
        $spring = LearningOutcome::query()->where('course_id', $course->id)->get()->keyBy('code');
        foreach ([['LO1', 'PO1', 3], ['LO1', 'PO2', 1], ['LO2', 'PO2', 3], ['LO2', 'PO3', 2], ['LO3', 'PO2', 2], ['LO3', 'PO5', 1], ['LO4', 'PO3', 3], ['LO4', 'PO5', 3]] as [$lo, $po, $level]) {
            if (isset($spring[$lo])) {
                \App\Models\CoPoMapping::query()->updateOrCreate(['course_id' => $course->id, 'learning_outcome_id' => $spring[$lo]->id, 'program_outcome_id' => $pos[$po]->id], ['mapping_level' => $level, 'created_by' => $owner->id]);
            }
        }

        // Calendar: the next two weeks
        foreach ([
            ['Midterm grading due', 'grading_deadline', now()->addDays(2)->setTime(17, 0), [1440, 60]],
            ['CSE311 final exam', 'exam', now()->addDays(9)->setTime(10, 0), [10080, 1440]],
            ['Department meeting', 'meeting', now()->addDays(4)->setTime(14, 30), [60]],
        ] as [$title, $type, $start, $reminders]) {
            \App\Models\AcademicEvent::query()->firstOrCreate(
                ['user_id' => $owner->id, 'title' => $title],
                ['type' => $type, 'course_id' => $type === 'meeting' ? null : $course->id, 'starts_at' => $start, 'location' => $type === 'meeting' ? 'Room 7A04' : 'Exam hall 3', 'reminder_offsets' => $reminders],
            );
        }
    }
}
