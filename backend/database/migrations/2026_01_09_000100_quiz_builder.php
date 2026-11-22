<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('shuffle_options')->default(false);
            // When students see their score and the correct answers: immediately | after_close | manual
            $table->string('show_results', 15)->default('immediately');
            $table->timestamp('results_released_at')->nullable(); // for "manual"
            // Give every student N random questions out of the quiz's questions (null = all).
            $table->unsignedSmallInteger('draw_count')->nullable();
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            // single | multiple | true_false | short | numeric
            $table->json('correct_options')->nullable();          // multiple: [0, 2]
            $table->decimal('correct_numeric', 12, 4)->nullable(); // numeric
            $table->decimal('tolerance', 12, 4)->nullable();       // numeric: +/- allowed
            $table->text('explanation')->nullable();               // shown with the results
        });
        DB::table('quiz_questions')->where('type', 'mcq')->update(['type' => 'single']);

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->json('question_ids')->nullable(); // the questions (and order) this student got
            $table->decimal('max_score', 8, 2)->nullable();
            $table->json('results')->nullable();      // per question: {score, correct}
        });

        // Reusable questions for a course, shared by every section and semester.
        Schema::create('question_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 15);
            $table->text('question');
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('correct_option')->nullable();
            $table->json('correct_options')->nullable();
            $table->decimal('correct_numeric', 12, 4)->nullable();
            $table->decimal('tolerance', 12, 4)->nullable();
            $table->decimal('marks', 5, 2)->default(1);
            $table->text('explanation')->nullable();
            $table->string('tags')->nullable(); // comma separated, e.g. "week1,arrays"
            $table->timestamps();
            $table->index(['course_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank');
        Schema::table('quiz_attempts', fn (Blueprint $table) => $table->dropColumn(['question_ids', 'max_score', 'results']));
        DB::table('quiz_questions')->where('type', 'single')->update(['type' => 'mcq']);
        Schema::table('quiz_questions', fn (Blueprint $table) => $table->dropColumn(['correct_options', 'correct_numeric', 'tolerance', 'explanation']));
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropColumn(['shuffle_options', 'show_results', 'results_released_at', 'draw_count']));
    }
};
