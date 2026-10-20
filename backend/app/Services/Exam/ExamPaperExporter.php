<?php

namespace App\Services\Exam;

use App\Models\ExamPaper;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/** Print-ready exam paper (PDF or Word), optionally followed by an answer key on a new page. */
class ExamPaperExporter
{
    public function filename(ExamPaper $paper, string $format, bool $answerKey): string
    {
        $paper->loadMissing('assessment.course');
        $base = preg_replace('/[^A-Za-z0-9]+/', '-', trim(($paper->assessment->course->course_code ?? 'exam').' '.$paper->title.' Set '.$paper->set_label));

        return trim($base, '-').($answerKey ? '-with-answer-key' : '').'.'.$format;
    }

    public function data(ExamPaper $paper): array
    {
        $paper->loadMissing(['assessment.course', 'items.learningOutcome']);
        $sections = [];
        $number = 0;
        foreach ($paper->items as $item) {
            $key = (string) $item->section_title;
            $sections[$key] ??= ['title' => $item->section_title, 'items' => []];
            $sections[$key]['items'][] = ['number' => ++$number, 'item' => $item];
        }

        return [
            'paper' => $paper,
            'course' => $paper->assessment->course,
            'assessment' => $paper->assessment,
            'sections' => array_values($sections),
        ];
    }

    public function pdf(ExamPaper $paper, bool $answerKey): string
    {
        return Pdf::loadView('exam.paper-pdf', $this->data($paper) + ['answerKey' => $answerKey])
            ->setPaper('a4', 'portrait')->output();
    }

    public function docx(ExamPaper $paper, bool $answerKey): string
    {
        $d = $this->data($paper);
        $word = new PhpWord();
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(11);
        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 40];

        $section->addText(e($d['course']->course_code.' — '.$d['course']->course_name), ['bold' => true, 'size' => 14], $center);
        $section->addText(e($d['paper']->title).'  ·  Set '.$d['paper']->set_label, ['bold' => true, 'size' => 12], $center);
        $meta = array_filter([
            $d['paper']->duration_minutes ? 'Time: '.$d['paper']->duration_minutes.' minutes' : null,
            'Full marks: '.rtrim(rtrim(number_format($d['paper']->total_marks, 2), '0'), '.'),
        ]);
        $section->addText(implode('      ', $meta), ['size' => 10], $center);
        if ($d['paper']->instructions) {
            $section->addText(e($d['paper']->instructions), ['italic' => true, 'size' => 10], ['spaceBefore' => 120, 'spaceAfter' => 200]);
        }

        $table = ['borderSize' => 0, 'cellMargin' => 40];
        foreach ($d['sections'] as $s) {
            if ($s['title']) {
                $section->addText(e($s['title']), ['bold' => true, 'size' => 11], ['spaceBefore' => 160, 'spaceAfter' => 80]);
            }
            foreach ($s['items'] as $row) {
                $item = $row['item'];
                $t = $section->addTable($table);
                $t->addRow();
                $t->addCell(500)->addText($row['number'].'.', ['bold' => true]);
                $cell = $t->addCell(8200);
                $cell->addText(e($item->question_text));
                foreach ((array) $item->options as $i => $option) {
                    $cell->addText('('.chr(97 + $i).') '.e(is_array($option) ? ($option['text'] ?? json_encode($option)) : (string) $option), ['size' => 10]);
                }
                $t->addCell(900)->addText('['.rtrim(rtrim(number_format($item->marks, 2), '0'), '.').']', ['bold' => true], ['alignment' => Jc::END]);
            }
        }

        if ($answerKey) {
            $section->addPageBreak();
            $section->addText('Answer key — Set '.$d['paper']->set_label.' (confidential)', ['bold' => true, 'size' => 13], $center);
            foreach ($d['sections'] as $s) {
                foreach ($s['items'] as $row) {
                    $item = $row['item'];
                    $section->addText($row['number'].'. '.($item->expected_answer ? e($item->expected_answer) : '— No expected answer recorded —'), [], ['spaceAfter' => 60]);
                    if ($item->answer_needs_review) {
                        $section->addText('   ⚠ Recompute: this variant changed values used in the answer.', ['color' => '9A3412', 'size' => 9]);
                    }
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'exam').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
