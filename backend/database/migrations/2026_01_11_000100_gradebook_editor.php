<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table) {
            // When on, work that is past due with no grade counts as 0 instead of being left out.
            $table->boolean('missing_as_zero')->default(false);
        });

        // A student excused from one assignment or quiz: it is left out of their average.
        Schema::create('gradebook_excusals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 12); // assignment | quiz
            $table->unsignedBigInteger('item_id');
            $table->foreignId('excused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'item_type', 'item_id']);
        });

        // The teacher's final say on a student's total, always with a reason.
        Schema::create('gradebook_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total', 5, 2);
            $table->string('note', 500);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_section_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gradebook_overrides');
        Schema::dropIfExists('gradebook_excusals');
        Schema::table('course_sections', fn (Blueprint $table) => $table->dropColumn('missing_as_zero'));
    }
};
