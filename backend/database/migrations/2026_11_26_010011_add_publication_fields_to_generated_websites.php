<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_websites', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->index();
            $table->string('public_slug', 180)->nullable()->unique();
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generated_websites', function (Blueprint $table) {
            $table->dropUnique('generated_websites_public_slug_unique');
            $table->dropIndex('generated_websites_is_published_index');
            $table->dropColumn(['is_published', 'public_slug', 'published_at']);
        });
    }
};
