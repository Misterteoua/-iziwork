<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('token')->unique();
            $table->timestamp('open_date')->nullable();
            $table->timestamp('close_date')->nullable();
            $table->integer('max_submissions')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('inactive');
            $table->foreignId('created_by')->constrained('admin_users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
