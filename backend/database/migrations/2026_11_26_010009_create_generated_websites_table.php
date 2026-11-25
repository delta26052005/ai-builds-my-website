<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('template_key', 40)->default('modern_business');
            $table->json('content_json')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('current_version')->default(0);
            $table->uuid('generation_token')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_websites');
    }
};
