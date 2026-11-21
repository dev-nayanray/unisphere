<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Topics group classwork inside a class ("Week 1", "Unit 2: Trees") and can be reordered.
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        foreach (['assignments', 'materials', 'quizzes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        // Turn the old free-text "topic" column into topic rows.
        foreach (['assignments', 'materials'] as $tableName) {
            $pairs = DB::table($tableName)->whereNotNull('topic')->where('topic', '!=', '')
                ->select('course_section_id', 'topic')->distinct()->get();

            foreach ($pairs as $pair) {
                $topicId = DB::table('topics')->where(['course_section_id' => $pair->course_section_id, 'name' => $pair->topic])->value('id')
                    ?? DB::table('topics')->insertGetId([
                        'course_section_id' => $pair->course_section_id, 'name' => $pair->topic,
                        'position' => DB::table('topics')->where('course_section_id', $pair->course_section_id)->count(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                DB::table($tableName)->where(['course_section_id' => $pair->course_section_id, 'topic' => $pair->topic])
                    ->update(['topic_id' => $topicId]);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('topic'));
        }

        Schema::table('assignments', function (Blueprint $table) {
            // accept | penalty (late_penalty_percent off per day late) | reject
            $table->string('late_policy', 10)->default('accept');
            $table->unsignedTinyInteger('late_penalty_percent')->default(0);
            $table->string('assign_to', 10)->default('all'); // all | selected
            $table->timestamp('notified_at')->nullable();
        });
        DB::table('assignments')->where('allow_late', false)->update(['late_policy' => 'reject']);
        DB::table('assignments')->whereNotNull('published_at')->update(['notified_at' => DB::raw('published_at')]);
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn('allow_late'));

        // Students chosen when an assignment is not for the whole class.
        Schema::create('assignment_student', function (Blueprint $table) {
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['assignment_id', 'student_id']);
        });

        Schema::create('assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        // Rubric: criteria, each with scored levels ("Excellent 5", "Good 3", ...).
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_criterion_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('description', 300)->nullable();
            $table->decimal('points', 6, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->json('rubric_scores')->nullable(); // {criterion_id: level_id}
        });
    }

    public function down(): void
    {
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('rubric_scores'));
        Schema::dropIfExists('rubric_levels');
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('assignment_attachments');
        Schema::dropIfExists('assignment_student');

        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('allow_late')->default(true);
            $table->dropColumn(['late_policy', 'late_penalty_percent', 'assign_to', 'notified_at']);
        });

        foreach (['assignments', 'materials', 'quizzes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropConstrainedForeignId('topic_id');
                if ($tableName !== 'quizzes') {
                    $table->string('topic', 80)->nullable();
                }
            });
        }

        Schema::dropIfExists('topics');
    }
};
