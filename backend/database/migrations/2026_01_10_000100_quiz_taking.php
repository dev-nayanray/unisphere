<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('display_mode', 10)->default('all'); // all | one (one question per page)
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->json('integrity')->nullable();        // {blur: 3, copy: 1, paste: 0, events: [...]}
            $table->timestamp('last_saved_at')->nullable(); // last autosave
            $table->boolean('auto_submitted')->default(false); // submitted by the server when time ran out
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', fn (Blueprint $table) => $table->dropColumn(['integrity', 'last_saved_at', 'auto_submitted']));
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropColumn('display_mode'));
    }
};
