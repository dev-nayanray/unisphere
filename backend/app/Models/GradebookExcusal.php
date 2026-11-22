<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradebookExcusal extends Model
{
    public const TYPES = ['assignment', 'quiz'];

    protected $fillable = ['course_section_id', 'student_id', 'item_type', 'item_id', 'excused_by'];
}
