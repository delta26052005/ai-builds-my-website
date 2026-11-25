<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_website_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('content_json');
            $table->string('change_summary', 500)->nullable();
            $table->timestamps();

            $table->unique(['generated_website_id', 'version_number'], 'website_revision_version_uq');
            $table->index(['generated_website_id', 'created_at'], 'website_revision_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_revisions');
    }
};
