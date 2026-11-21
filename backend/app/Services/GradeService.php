<?php

namespace App\Services;

class GradeService
{
    /**
     * Convert a percentage (0-100) to [letter grade, grade point]
     * using the UGC Bangladesh uniform grading scale in config/unisphere.php.
     *
     * @return array{0: string, 1: float}
     */
    public function grade(float $percentage): array
    {
        foreach (config('unisphere.grade_scale') as [$min, $letter, $point]) {
            if ($percentage >= $min) {
                return [$letter, (float) $point];
            }
        }

        return ['F', 0.0];
    }
}
