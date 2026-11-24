<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_research_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->string('source_url', 2048);
            $table->char('url_hash', 64);
            $table->string('title', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->longText('content')->nullable();
            $table->string('access_status', 30)->default('inaccessible')->index();
            $table->string('access_message', 500)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['website_project_id', 'url_hash']);
            $table->index(['website_project_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_research_sources');
    }
};
