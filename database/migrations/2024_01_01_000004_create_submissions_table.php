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
            $table->string('student_email');
            $table->string('student_phone')->nullable();
            $table->string('student_major')->nullable();
            $table->string('anonymous_code')->nullable()->unique();
            $table->enum('status', ['pending', 'validated'])->default('pending');
            $table->string('ip_address')->nullable();
            $table->timestamps();
            
            $table->unique(['form_id', 'student_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
