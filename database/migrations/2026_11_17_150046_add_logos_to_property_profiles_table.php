<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_profiles', function (Blueprint $table) {
            // Paths on the public disk. The dark version is for the footer, emails and dark admin mode.
            $table->string('logo_path')->nullable()->after('theme');
            $table->string('logo_dark_path')->nullable()->after('logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_profiles', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_dark_path']);
        });
    }
};
