<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('receipt_token', 64)->nullable()->unique()->after('anonymous_code');
        });

        // Preserve access to recaps created before this migration without
        // exposing predictable numeric submission IDs.
        DB::table('submissions')->whereNull('receipt_token')->orderBy('id')->eachById(function ($submission): void {
            DB::table('submissions')
                ->where('id', $submission->id)
                ->update(['receipt_token' => Str::random(64)]);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique(['receipt_token']);
            $table->dropColumn('receipt_token');
        });
    }
};
