<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Students see marks and feedback only after the teacher returns them.
            $table->timestamp('returned_at')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->string('file_disk', 10)->default('local'); // older uploads live on "public"
        });

        // Existing graded work counts as returned; existing files stay on the public disk.
        DB::table('submissions')->whereNotNull('marks')->update(['returned_at' => DB::raw('graded_at')]);
        DB::table('submissions')->whereNotNull('file_path')->update(['file_disk' => 'public']);

        // Every turn-in is kept, so teachers can see what changed between attempts.
        Schema::create('submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->text('content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->timestamps();
        });

        // Reusable feedback snippets per teacher.
        Schema::create('feedback_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('text', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_comments');
        Schema::dropIfExists('submission_versions');
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn(['returned_at', 'file_name', 'file_mime', 'file_disk']));
    }
};
