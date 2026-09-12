<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');
            $table->string('student_name')->nullable();
            // Nullable since the 2026_09_12 revision: an admin can blank the
            // email when fixing a student mistake. The anti-duplicate guard
            // lives in SubmissionController (it must allow one NULL per
            // form), not in a unique index (SQLite would treat NULLs as
            // duplicates and MySQL would not).
            $table->string('student_email')->nullable();
            $table->string('student_phone')->nullable();
            $table->string('student_major')->nullable();
            $table->string('anonymous_code')->nullable()->unique();
            $table->enum('status', ['pending', 'validated'])->default('pending');
            $table->string('ip_address')->nullable();
            $table->timestamps();
            
            // No unique index on (form_id, student_email): see the column
            // comment above. Duplicates are rejected at the controller level,
            // which also lets an admin fix a wrong email after the fact.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
