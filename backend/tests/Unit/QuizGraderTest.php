<?php

namespace Tests\Unit;

use App\Models\QuizQuestion;
use App\Services\QuizGrader;
use Tests\TestCase;

class QuizGraderTest extends TestCase
{
    private function q(array $attrs): QuizQuestion
    {
        return new QuizQuestion(['marks' => 2, ...$attrs]);
    }

    public function test_single_choice(): void
    {
        $q = $this->q(['type' => 'single', 'options' => ['a', 'b', 'c'], 'correct_option' => 1]);
        $g = new QuizGrader;

        $this->assertSame(['score' => 2.0, 'correct' => true], $g->grade($q, 1));
        $this->assertSame(['score' => 2.0, 'correct' => true], $g->grade($q, '1'));
        $this->assertSame(['score' => 0.0, 'correct' => false], $g->grade($q, 2));
        $this->assertSame(['score' => 0.0, 'correct' => false], $g->grade($q, null));
    }

    public function test_true_false(): void
    {
        $q = $this->q(['type' => 'true_false', 'options' => ['True', 'False'], 'correct_option' => 0]);

        $this->assertTrue((new QuizGrader)->grade($q, 0)['correct']);
        $this->assertFalse((new QuizGrader)->grade($q, 1)['correct']);
    }

    public function test_multiple_answer_needs_the_exact_set(): void
    {
        $q = $this->q(['type' => 'multiple', 'options' => ['2', '4', '5', '9'], 'correct_options' => [0, 2]]);
        $g = new QuizGrader;

        $this->assertTrue($g->grade($q, [2, 0])['correct']);   // order doesn't matter
        $this->assertFalse($g->grade($q, [0])['correct']);     // missing one
        $this->assertFalse($g->grade($q, [0, 2, 3])['correct']); // one extra
    }

    public function test_numeric_with_tolerance(): void
    {
        $q = $this->q(['type' => 'numeric', 'correct_numeric' => 3.14, 'tolerance' => 0.01]);
        $g = new QuizGrader;

        $this->assertTrue($g->grade($q, '3.15')['correct']);
        $this->assertTrue($g->grade($q, 3.13)['correct']);
        $this->assertFalse($g->grade($q, 3.2)['correct']);
        $this->assertFalse($g->grade($q, 'pi')['correct']);
    }

    public function test_short_answers_wait_for_the_teacher(): void
    {
        $q = $this->q(['type' => 'short']);

        $this->assertSame(['score' => null, 'correct' => null], (new QuizGrader)->grade($q, 'Normalisation removes redundancy.'));
    }

    public function test_grade_all_totals_and_flags_manual_work(): void
    {
        $single = $this->q(['id' => 1, 'type' => 'single', 'options' => ['a', 'b'], 'correct_option' => 0]);
        $short = $this->q(['id' => 2, 'type' => 'short']);
        $single->id = 1;
        $short->id = 2;
        $g = new QuizGrader;

        $all = $g->gradeAll([$single], [1 => 0]);
        $this->assertSame(2.0, $all['score']);

        $mixed = $g->gradeAll([$single, $short], [1 => 0, 2 => 'text']);
        $this->assertNull($mixed['score']);       // needs the teacher
        $this->assertSame(2.0, $mixed['auto_score']);
    }
}
