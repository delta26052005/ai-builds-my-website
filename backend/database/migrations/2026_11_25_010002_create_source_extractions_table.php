<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_source_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->longText('extracted_text')->nullable();
            $table->string('extraction_method', 100)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_extractions');
    }
};
