<?php

namespace Tests\Unit;

use App\Services\GradeService;
use Tests\TestCase;

class GradeServiceTest extends TestCase
{
    public function test_it_uses_the_ugc_grading_scale(): void
    {
        $grades = new GradeService;

        $this->assertSame(['A+', 4.0], $grades->grade(85));
        $this->assertSame(['A', 3.75], $grades->grade(75));
        $this->assertSame(['B+', 3.25], $grades->grade(67.5));
        $this->assertSame(['D', 2.0], $grades->grade(40));
        $this->assertSame(['F', 0.0], $grades->grade(39.99));
    }
}
