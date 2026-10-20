<?php

namespace Tests\Feature;

use App\Jobs\RunAiToolJob;
use App\Models\AiToolJob;
use App\Models\User;
use App\Services\Admin\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiToolkitTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->faculty = User::factory()->create();
    }

    public function test_requires_authentication_and_reports_enabled_tools(): void
    {
        $this->getJson('/api/ai-toolkit')->assertStatus(401);
        Sanctum::actingAs($this->faculty);
        $this->getJson('/api/ai-toolkit')->assertOk()
            ->assertJsonPath('data.tools.translation', true)
            ->assertJsonPath('data.provider', 'local');
    }

    public function test_translation_sends_the_admin_selected_provider(): void
    {
        Http::fake(['*/api/v1/toolkit/translate' => Http::response(['status' => 'success', 'text' => 'শুভ সকাল', 'model' => 'facebook/nllb-200-distilled-600M', 'provider' => 'local'])]);
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/ai-toolkit/translate', ['text' => 'Good morning', 'source' => 'en', 'target' => 'bn'])
            ->assertOk()->assertJsonPath('data.text', 'শুভ সকাল')->assertJsonPath('data.provider', 'local');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-AI-Provider', 'local') && $r['source'] === 'en' && $r['target'] === 'bn');

        app(FeatureFlagService::class)->update(['ai_provider' => 'hosted']);
        $this->postJson('/api/ai-toolkit/translate', ['text' => 'Good morning', 'source' => 'en', 'target' => 'bn'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-AI-Provider', 'hosted'));

        $this->postJson('/api/ai-toolkit/translate', ['text' => 'x', 'source' => 'en', 'target' => 'en'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_TRANSLATION']);
    }

    public function test_switched_off_tools_refuse_new_work(): void
    {
        app(FeatureFlagService::class)->update(['ai_translation' => false, 'ai_ocr' => false]);
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/ai-toolkit/translate', ['text' => 'Hello', 'source' => 'en', 'target' => 'bn'])->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
        $this->post('/api/ai-toolkit/ocr', ['file' => UploadedFile::fake()->image('script.png')], ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson('/api/ai-toolkit')->assertOk()->assertJsonPath('data.tools.ocr', false);
    }

    public function test_ocr_job_completes_keeps_text_and_deletes_the_upload(): void
    {
        Http::fake(['*/api/v1/toolkit/ocr' => Http::response(['status' => 'success', 'text' => "Q1. BFS visits level by level.", 'pages' => [['page' => 1, 'lines' => ['Q1. BFS visits level by level.']]],
            'line_count' => 1, 'model' => 'microsoft/trocr-base-handwritten', 'provider' => 'local', 'note' => 'Draft transcription'])]);
        Sanctum::actingAs($this->faculty);

        $id = $this->post('/api/ai-toolkit/ocr', ['file' => UploadedFile::fake()->image('script.png', 800, 600)], ['Accept' => 'application/json'])
            ->assertStatus(202)->json('data.id');

        $job = AiToolJob::query()->findOrFail($id);
        $this->assertSame('completed', $job->status); // sync queue in tests
        $this->assertSame('Q1. BFS visits level by level.', $job->result_text);
        $this->assertNull($job->input_path);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-toolkit'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/toolkit/ocr') && $r->isMultipart());

        $this->getJson("/api/ai-toolkit/jobs/{$id}")->assertOk()->assertJsonPath('data.text', 'Q1. BFS visits level by level.')->assertJsonPath('data.line_count', 1);
        $this->assertStringContainsString('BFS', $this->get("/api/ai-toolkit/jobs/{$id}/download")->assertOk()->streamedContent());
    }

    public function test_failed_transcription_records_the_safe_reason_and_still_deletes_the_upload(): void
    {
        Http::fake(['*/api/v1/toolkit/transcribe' => Http::response(['detail' => 'The recording is 120 minutes long; the limit is 90 minutes.'], 422)]);
        Sanctum::actingAs($this->faculty);

        $id = $this->post('/api/ai-toolkit/transcribe', ['file' => UploadedFile::fake()->create('lecture.mp3', 500, 'audio/mpeg'), 'language' => 'bn'], ['Accept' => 'application/json'])
            ->assertStatus(202)->json('data.id');

        $job = AiToolJob::query()->findOrFail($id);
        $this->assertSame('failed', $job->status);
        $this->assertSame('The recording is 120 minutes long; the limit is 90 minutes.', $job->error);
        $this->assertSame('bn', $job->language);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-toolkit'));
    }

    public function test_uploads_are_validated_and_queued_on_the_ai_tools_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);

        $this->post('/api/ai-toolkit/transcribe', ['file' => UploadedFile::fake()->create('notes.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/ai-toolkit/ocr', ['file' => UploadedFile::fake()->create('doc.docx', 10)], ['Accept' => 'application/json'])->assertStatus(422);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/ai-toolkit/ocr', ['file' => UploadedFile::fake()->image("p{$i}.png")], ['Accept' => 'application/json'])->assertStatus(202);
        }
        $this->post('/api/ai-toolkit/ocr', ['file' => UploadedFile::fake()->image('p4.png')], ['Accept' => 'application/json'])->assertStatus(429);
        Queue::assertPushedOn('ai-tools', RunAiToolJob::class);
        Queue::assertPushed(RunAiToolJob::class, 3);
    }

    public function test_jobs_are_private_to_their_owner(): void
    {
        $job = AiToolJob::query()->create(['user_id' => $this->faculty->id, 'tool' => 'ocr', 'status' => 'completed', 'input_filename' => 'a.png', 'result_text' => 'secret answer']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/ai-toolkit/jobs/{$job->id}")->assertStatus(404);
        $this->get("/api/ai-toolkit/jobs/{$job->id}/download")->assertStatus(404);
        $this->deleteJson("/api/ai-toolkit/jobs/{$job->id}")->assertStatus(404);
        $this->getJson('/api/ai-toolkit')->assertOk()->assertJsonCount(0, 'data.jobs');
    }

    // ------------------------------------------------------------------ study pack, speech, writing tools

    protected function lecture(): string
    {
        return str_repeat('Breadth-first search explores a graph level by level and uses a queue. Depth-first search goes deep and uses a stack. ', 4);
    }

    public function test_study_pack_runs_as_a_job_and_can_start_from_a_transcript(): void
    {
        $pack = ['status' => 'success', 'title' => 'Graphs', 'summary' => 'BFS goes level by level; DFS goes deep.', 'summary_method' => 'generative',
            'key_points' => ['BFS uses a queue.'], 'keywords' => ['queue'], 'quiz_method' => 'generative', 'quiz_rejected' => 1, 'model' => 'Qwen/Qwen2.5-0.5B-Instruct',
            'quiz' => [['question' => 'What data structure does BFS use?', 'answer' => 'A queue.', 'type' => 'short_answer', 'bloom_level' => 'REMEMBER', 'evidence' => 'BFS uses a queue.']]];
        Http::fake(['*/api/v1/toolkit/study-pack' => Http::response($pack)]);
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/ai-toolkit/study-pack', ['text' => 'too short'])->assertStatus(422);
        $id = $this->postJson('/api/ai-toolkit/study-pack', ['text' => $this->lecture(), 'title' => 'Graphs', 'num_questions' => 3])->assertStatus(202)->json('data.id');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'study-pack') && $r['num_questions'] === 3 && $r['title'] === 'Graphs');

        $this->getJson("/api/ai-toolkit/jobs/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.quiz.0.evidence', 'BFS uses a queue.')
            ->assertJsonPath('data.quiz_rejected', 1);
        $this->assertStringContainsString('QUIZ (draft', AiToolJob::query()->findOrFail($id)->result_text);
        $this->assertSame([], Storage::disk('local')->allFiles('ai-toolkit'));

        // From a finished transcript the user owns (and never from someone else's)
        $transcript = AiToolJob::query()->create(['user_id' => $this->faculty->id, 'tool' => 'transcription', 'status' => 'completed', 'input_filename' => 'week3.mp3', 'result_text' => $this->lecture()]);
        $this->postJson('/api/ai-toolkit/study-pack', ['source_job_id' => $transcript->id])->assertStatus(202)->assertJsonPath('data.input_filename', 'week3');
        $foreign = AiToolJob::query()->create(['user_id' => User::factory()->create()->id, 'tool' => 'transcription', 'status' => 'completed', 'input_filename' => 'x.mp3', 'result_text' => $this->lecture()]);
        $this->postJson('/api/ai-toolkit/study-pack', ['source_job_id' => $foreign->id])->assertStatus(422);
    }

    public function test_speech_returns_audio_and_validates_language(): void
    {
        Http::fake(['*/api/v1/toolkit/speak' => Http::response('RIFF....WAVEfmt ', 200, ['Content-Type' => 'audio/wav', 'X-Audio-Seconds' => '2.5', 'X-Model' => 'facebook/mms-tts-ben'])]);
        Sanctum::actingAs($this->faculty);

        $res = $this->post('/api/ai-toolkit/speak', ['text' => 'শুভ সকাল', 'language' => 'bn'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('audio/wav', $res->headers->get('Content-Type'));
        $this->assertSame('2.5', $res->headers->get('X-Audio-Seconds'));
        $this->postJson('/api/ai-toolkit/speak', ['text' => 'Bonjour', 'language' => 'fr'])->assertStatus(422);
    }

    public function test_writing_tools_proxy_results_and_can_be_switched_off(): void
    {
        Http::fake([
            '*/api/v1/toolkit/question-quality' => Http::response(['status' => 'success', 'results' => [['question' => 'what is bfs', 'score' => 76, 'issues' => [['code' => 'grammar']], 'suggested_rewrite' => 'What is breadth-first search?']], 'rewrites_available' => true]),
            '*/api/v1/toolkit/simplify' => Http::response(['status' => 'success', 'text' => 'Programs get stuck.', 'simpler' => true]),
            '*/api/v1/toolkit/bloom-rewrite' => Http::response(['status' => 'success', 'rewritten' => 'Trace BFS on this graph.', 'matches_target' => true, 'on_topic' => true]),
        ]);
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/ai-toolkit/question-quality', ['questions' => ['what is bfs']])->assertOk()->assertJsonPath('data.results.0.suggested_rewrite', 'What is breadth-first search?');
        $this->postJson('/api/ai-toolkit/simplify', ['text' => str_repeat('Deadlock is a complex state. ', 3), 'level' => 'school'])->assertOk()->assertJsonPath('data.simpler', true);
        $this->postJson('/api/ai-toolkit/bloom-rewrite', ['question' => 'Define breadth-first search.', 'target_level' => 'APPLY'])->assertOk()->assertJsonPath('data.matches_target', true);
        $this->postJson('/api/ai-toolkit/bloom-rewrite', ['question' => 'Define BFS please.', 'target_level' => 'GUESS'])->assertStatus(422);
        $this->postJson('/api/ai-toolkit/question-quality', ['questions' => array_fill(0, 16, 'x')])->assertStatus(422);

        app(FeatureFlagService::class)->update(['ai_writing' => false]);
        $this->postJson('/api/ai-toolkit/simplify', ['text' => str_repeat('Deadlock is a complex state. ', 3), 'level' => 'school'])->assertStatus(403);
    }

    // ------------------------------------------------------------------ OCR → grading, quiz → question bank

    protected function gradingFixture(User $owner): array
    {
        $course = \App\Models\Course::query()->create(['user_id' => $owner->id, 'course_code' => 'CSE220', 'course_name' => 'Data Structures', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $assessment = \App\Models\Assessment::query()->create(['course_id' => $course->id, 'title' => 'Midterm', 'total_marks' => 20]);
        $question = \App\Models\Question::query()->create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => 'Explain BFS.', 'marks' => 10]);
        $student = \App\Models\Student::query()->create(['created_by' => $owner->id, 'student_identifier' => (string) random_int(100000, 999999), 'name' => 'Student One']);
        $submission = \App\Models\StudentSubmission::query()->create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'submission_identifier' => 'S-1', 'status' => 'SUBMITTED']);

        return compact('course', 'assessment', 'question', 'submission');
    }

    public function test_reviewed_ocr_text_can_be_attached_to_a_students_answer(): void
    {
        ['question' => $question, 'submission' => $submission] = $this->gradingFixture($this->faculty);
        $ocr = AiToolJob::query()->create(['user_id' => $this->faculty->id, 'tool' => 'ocr', 'status' => 'completed', 'input_filename' => 'script.jpg', 'result_text' => 'BFS visits level by levl']);
        Sanctum::actingAs($this->faculty);

        $payload = ['submission_id' => $submission->id, 'question_id' => $question->id, 'text' => 'BFS visits level by level.'];
        $answerId = $this->postJson("/api/ai-toolkit/jobs/{$ocr->id}/attach", $payload)->assertCreated()->json('data.answer_id');
        $this->assertSame('BFS visits level by level.', \App\Models\StudentAnswer::query()->findOrFail($answerId)->answer_text);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_OCR_ATTACHED', 'entity_id' => $answerId]);

        // An existing answer is only replaced on explicit confirmation, and its original text is preserved
        $this->postJson("/api/ai-toolkit/jobs/{$ocr->id}/attach", ['text' => 'Second scan'] + $payload)->assertStatus(409)->assertJsonPath('code', 'ANSWER_EXISTS');
        $this->postJson("/api/ai-toolkit/jobs/{$ocr->id}/attach", ['text' => 'Second scan', 'replace' => true] + $payload)->assertOk();
        $answer = \App\Models\StudentAnswer::query()->findOrFail($answerId);
        $this->assertSame('Second scan', $answer->answer_text);
        $this->assertSame('BFS visits level by level.', $answer->original_answer_text);

        // A question from another assessment is refused by the existing submission rules
        $other = $this->gradingFixture($this->faculty)['question'];
        $this->postJson("/api/ai-toolkit/jobs/{$ocr->id}/attach", ['question_id' => $other->id] + $payload)->assertStatus(422);
    }

    // ------------------------------------------------------------------ feedback drafter, answer comparison

    protected function answerWithRubric(User $owner, bool $approved = true): \App\Models\StudentAnswer
    {
        ['question' => $question, 'submission' => $submission] = $this->gradingFixture($owner);
        $rubric = \App\Models\Rubric::query()->create(['question_id' => $question->id, 'assessment_id' => $question->assessment_id, 'created_by' => $owner->id,
            'title' => 'BFS rubric', 'total_marks' => 10, 'status' => $approved ? 'APPROVED' : 'DRAFT', 'version' => 1, 'generation_method' => 'manual']);
        $rubric->criteria()->create(['criterion' => 'Traversal order', 'description' => 'Explains level-by-level exploration', 'max_marks' => 6, 'expected_indicators' => ['Uses a queue'], 'sort_order' => 1]);
        $rubric->criteria()->create(['criterion' => 'Shortest path', 'description' => 'Shortest paths in unweighted graphs', 'max_marks' => 4, 'expected_indicators' => [], 'sort_order' => 2]);

        return \App\Models\StudentAnswer::query()->create(['student_submission_id' => $submission->id, 'question_id' => $question->id, 'answer_type' => 'TEXT', 'answer_text' => 'BFS visits vertices level by level using a queue.']);
    }

    protected function fakeFeedback(): void
    {
        Http::fake(['*/api/v1/toolkit/feedback-draft' => Http::response(['status' => 'success', 'feedback' => 'Good start: you explained the level-by-level order. To improve, cover shortest paths.',
            'tone' => 'encouraging', 'criteria' => [], 'method' => 'rubric_template', 'model' => null, 'note' => 'Draft feedback only'])]);
    }

    public function test_feedback_draft_for_a_stored_answer_uses_its_approved_rubric_and_saves_nothing(): void
    {
        $this->fakeFeedback();
        $answer = $this->answerWithRubric($this->faculty);
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/ai-toolkit/feedback-draft', ['student_answer_id' => $answer->id, 'tone' => 'neutral'])
            ->assertOk()->assertJsonPath('data.method', 'rubric_template')->assertJsonMissingPath('data.suggested_marks');

        // The trusted payload is built from the database, never from the browser
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/toolkit/feedback-draft')
            && $r['question'] === 'Explain BFS.' && $r['answer'] === 'BFS visits vertices level by level using a queue.'
            && $r['tone'] === 'neutral' && count($r['criteria']) === 2 && $r['criteria'][0]['indicators'] === ['Uses a queue']
            && !array_key_exists('max_marks', $r['criteria'][0]));
        $fresh = $answer->fresh();
        $this->assertNull($fresh->awarded_marks ?? null);
        $this->assertNull($fresh->faculty_feedback ?? null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_TOOL_USED']);
    }

    public function test_feedback_draft_needs_an_approved_rubric_and_access_to_the_answer(): void
    {
        $this->fakeFeedback();
        Sanctum::actingAs($this->faculty);
        $draftOnly = $this->answerWithRubric($this->faculty, approved: false);
        $this->postJson('/api/ai-toolkit/feedback-draft', ['student_answer_id' => $draftOnly->id])->assertStatus(422)->assertJsonPath('code', 'NO_RUBRIC');

        $foreign = $this->answerWithRubric(User::factory()->create());
        $this->postJson('/api/ai-toolkit/feedback-draft', ['student_answer_id' => $foreign->id])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_feedback_draft_for_pasted_text_and_switch(): void
    {
        $this->fakeFeedback();
        Sanctum::actingAs($this->faculty);
        $payload = ['question' => 'Explain BFS.', 'answer' => 'BFS uses a queue.', 'criteria' => [['criterion' => 'Traversal order', 'indicators' => ['Uses a queue']]]];
        $this->postJson('/api/ai-toolkit/feedback-draft', $payload)->assertOk();
        $this->postJson('/api/ai-toolkit/feedback-draft', ['question' => 'Explain BFS.'])->assertStatus(422);

        app(FeatureFlagService::class)->update(['ai_feedback' => false]);
        $this->postJson('/api/ai-toolkit/feedback-draft', $payload)->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
        $this->getJson('/api/ai-toolkit')->assertJsonPath('data.tools.feedback', false);
    }

    public function test_compare_answers_flags_copied_text_and_ignores_the_quoted_question(): void
    {
        Http::fake(['*/api/v1/toolkit/answer-embeddings' => Http::response(['detail' => 'down'], 503), '*/api/v1/embeddings/batch' => Http::response(['detail' => 'down'], 503)]);
        Sanctum::actingAs($this->faculty);
        $copied = 'An index keeps a sorted structure such as a B+ tree so the database jumps to matching rows instead of scanning the entire table.';
        $res = $this->postJson('/api/ai-toolkit/compare-answers', [
            'question' => 'Explain why indexes speed up queries.',
            'answers' => [
                ['label' => 'Rahim', 'text' => $copied],
                ['label' => 'Karim', 'text' => 'In my opinion '.$copied],
                ['label' => 'Nadia', 'text' => 'Indexes reduce disk reads because lookups use logarithmic search over ordered keys rather than reading every page.'],
                ['text' => 'Too short.'],
            ],
        ])->assertOk();

        $data = $res->json('data');
        $this->assertSame('lexical', $data['method']);
        $this->assertTrue($data['semantic_unavailable']);
        $this->assertSame(['Answer 4'], $data['too_short']);
        $this->assertSame(1, $data['pairs_flagged']);
        $this->assertSame(['Rahim', 'Karim'], [$data['pairs'][0]['a'], $data['pairs'][0]['b']]);
        $this->assertNotNull($data['pairs'][0]['shared_excerpt']);
        $this->assertFalse($data['pairs'][1]['flagged']);

        $this->postJson('/api/ai-toolkit/compare-answers', ['answers' => [['label' => 'A', 'text' => 'x'], ['label' => 'A', 'text' => 'y']]])->assertStatus(422);
        $this->postJson('/api/ai-toolkit/compare-answers', ['answers' => [['text' => 'only one']]])->assertStatus(422);
    }

    public function test_compare_answers_uses_bge_m3_embeddings_and_falls_back_to_minilm(): void
    {
        Sanctum::actingAs($this->faculty);
        $answers = ['answers' => [
            ['label' => 'Rahim', 'text' => 'A graph search that visits every neighbour before going one level deeper in the graph.'],
            ['label' => 'Karim', 'text' => 'গ্রাফের প্রতিটি প্রতিবেশী নোড আগে দেখা হয়, তারপর এক স্তর গভীরে যাওয়া হয় এই অনুসন্ধানে।'],
            ['label' => 'Nadia', 'text' => 'Indexes reduce disk reads because lookups use logarithmic search over ordered keys instead.'],
        ]];
        $vectors = ['vectors' => [[1, 0, 0], [0.98, 0.2, 0], [0, 0, 1]], 'embedding_dimension' => 3];

        Http::fake(['*/api/v1/toolkit/answer-embeddings' => Http::response(['status' => 'success', 'model' => 'BAAI/bge-m3'] + $vectors)]);
        $data = $this->postJson('/api/ai-toolkit/compare-answers', $answers)->assertOk()->json('data');
        $this->assertSame('lexical+semantic', $data['method']);
        $this->assertSame('BAAI/bge-m3', $data['embedding_model']);
        $this->assertSame(['Rahim', 'Karim'], [$data['pairs'][0]['a'], $data['pairs'][0]['b']]); // same idea across languages ranks first
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v1/toolkit/answer-embeddings') && count($r['texts']) === 3);
    }

    public function test_compare_answers_falls_back_to_minilm_when_the_answer_model_is_missing(): void
    {
        Sanctum::actingAs($this->faculty);
        $answers = ['answers' => [
            ['label' => 'Rahim', 'text' => 'A graph search that visits every neighbour before going one level deeper in the graph.'],
            ['label' => 'Karim', 'text' => 'Breadth first search looks at all neighbours first and only then moves one level deeper.'],
        ]];
        $vectors = ['vectors' => [[1, 0], [0.9, 0.1]], 'embedding_dimension' => 2];
        // Older AI service without the endpoint: the shared MiniLM embeddings are used instead
        Http::fake([
            '*/api/v1/toolkit/answer-embeddings' => Http::response(['detail' => 'Not Found'], 404),
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'model' => 'sentence-transformers/all-MiniLM-L6-v2'] + $vectors),
        ]);
        $data = $this->postJson('/api/ai-toolkit/compare-answers', $answers)->assertOk()->json('data');
        $this->assertSame('lexical+semantic', $data['method']);
        $this->assertSame('sentence-transformers/all-MiniLM-L6-v2', $data['embedding_model']);
    }

    public function test_attach_is_refused_for_other_peoples_submissions(): void
    {
        ['question' => $question, 'submission' => $submission] = $this->gradingFixture(User::factory()->create());
        $ocr = AiToolJob::query()->create(['user_id' => $this->faculty->id, 'tool' => 'ocr', 'status' => 'completed', 'input_filename' => 's.jpg', 'result_text' => 'x']);
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/ai-toolkit/jobs/{$ocr->id}/attach", ['submission_id' => $submission->id, 'question_id' => $question->id, 'text' => 'x'])->assertStatus(403);
        $this->assertSame(0, \App\Models\StudentAnswer::query()->count());
    }

    public function test_selected_quiz_questions_can_be_saved_to_a_course_question_bank(): void
    {
        ['course' => $course] = $this->gradingFixture($this->faculty);
        $pack = AiToolJob::query()->create(['user_id' => $this->faculty->id, 'tool' => 'study_pack', 'status' => 'completed', 'input_filename' => 'Graphs', 'result' => ['quiz' => []]]);
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/ai-toolkit/jobs/{$pack->id}/question-bank", ['course_id' => $course->id, 'questions' => [
            ['question' => 'What data structure does BFS use?', 'bloom_level' => 'REMEMBER'],
            ['question' => 'Fill in the blank: _____ search explores level by level.', 'bloom_level' => 'REMEMBER'],
        ]])->assertCreated()->assertJsonPath('message', '2 question(s) saved to the CSE220 question bank.');
        $this->assertDatabaseHas('previous_questions', ['course_id' => $course->id, 'question_text' => 'What data structure does BFS use?', 'question_type' => 'short_answer', 'cognitive_level' => 'Remember']);

        // Someone else's course is refused
        $foreignCourse = $this->gradingFixture(User::factory()->create())['course'];
        $this->postJson("/api/ai-toolkit/jobs/{$pack->id}/question-bank", ['course_id' => $foreignCourse->id, 'questions' => [['question' => 'Q?']]])->assertStatus(403);
    }

    public function test_admin_can_switch_provider_only_to_known_values(): void
    {
        config(['admin.email' => '']);
        $admin = User::factory()->create(['role' => 'ADMIN']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings', ['features' => ['ai_provider' => 'cloud-xyz']])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['features' => ['ai_provider' => 'hosted'], 'reason' => 'Faster answers'])->assertOk();
        $this->assertSame('hosted', app(FeatureFlagService::class)->get('ai_provider'));

        $flag = collect($this->getJson('/api/admin/settings')->json('data.features'))->firstWhere('key', 'ai_provider');
        $this->assertSame('select', $flag['type']);
        $this->assertSame(['local', 'hosted'], array_column($flag['options'], 'value'));

        $memory = ['process_mb' => 2100, 'budget_mb' => 5000, 'idle_unload_seconds' => 900, 'loaded' => [['name' => 'transcription', 'idle_seconds' => 40, 'in_use' => false]]];
        Http::fake(['*/api/v1/toolkit/status' => Http::response(['status' => 'success', 'generation' => ['hosted' => ['configured' => true, 'model' => 'm']], 'tools' => [], 'memory' => $memory])]);
        $this->getJson('/api/admin/ai')->assertOk()->assertJsonPath('data.provider.selected', 'hosted')->assertJsonPath('data.provider.toolkit.available', true)
            ->assertJsonPath('data.provider.toolkit.memory', $memory);
    }
}
