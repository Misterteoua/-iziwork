<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== "mysql") {
            return;
        }

        Schema::table("submissions", function (Blueprint $table) {
            $table->string("student_email")->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== "mysql") {
            return;
        }

        Schema::table("submissions", function (Blueprint $table) {
            $table->string("student_email")->nullable(false)->change();
        });
    }
};
