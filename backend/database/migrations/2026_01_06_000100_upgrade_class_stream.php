<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('type', 20)->default('announcement'); // announcement, classwork
            $table->json('link')->nullable();                      // {kind: assignment|quiz|material, id}
            $table->timestamp('published_at')->nullable()->index(); // future = scheduled
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('edited_at')->nullable();
        });

        // Existing posts were published when created.
        DB::table('posts')->update(['published_at' => DB::raw('created_at'), 'notified_at' => DB::raw('created_at')]);

        Schema::table('post_comments', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
        });

        Schema::create('post_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        Schema::table('course_sections', function (Blueprint $table) {
            // Who may use the stream: students post and comment / only comment / neither.
            $table->string('stream_permission', 20)->default('post_comment');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', fn (Blueprint $table) => $table->dropColumn('stream_permission'));
        Schema::dropIfExists('post_attachments');
        Schema::table('post_comments', fn (Blueprint $table) => $table->dropColumn('edited_at'));
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn(['type', 'link', 'published_at', 'notified_at', 'edited_at']);
        });
    }
};
