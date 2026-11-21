<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('note', 500)->nullable();          // advisor's note on approve/reject
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('waitlisted_at')->nullable();   // queue order when a section is full
            $table->boolean('is_retake')->default(false);     // grade-improvement retake
            $table->boolean('forced')->default(false);        // added by the registrar over capacity
            $table->index(['course_section_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex(['course_section_id', 'status']);
            $table->dropColumn(['note', 'decided_at', 'waitlisted_at', 'is_retake', 'forced']);
        });
    }
};
