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
            // Homepage hero text, edited on the admin "Homepage" page. Empty means the built-in wording.
            $table->string('hero_title')->nullable()->after('theme');
            $table->string('hero_subtitle', 500)->nullable()->after('hero_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_profiles', function (Blueprint $table) {
            $table->dropColumn(['hero_title', 'hero_subtitle']);
        });
    }
};
