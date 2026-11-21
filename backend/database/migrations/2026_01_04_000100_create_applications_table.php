<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Online admission applications submitted from the public /apply page.
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_code', 12)->unique();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20);
            $table->date('date_of_birth');
            $table->string('gender', 10);
            $table->string('address', 500);
            $table->decimal('ssc_gpa', 3, 2);
            $table->unsignedSmallInteger('ssc_year');
            $table->decimal('hsc_gpa', 3, 2);
            $table->unsignedSmallInteger('hsc_year');
            $table->json('documents')->nullable(); // [{label, path, original_name}]
            $table->string('status', 20)->default('submitted'); // submitted, under_review, accepted, rejected
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // account created on acceptance
            $table->timestamps();
            $table->index(['status', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
