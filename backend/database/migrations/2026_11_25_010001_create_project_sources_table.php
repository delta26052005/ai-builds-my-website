<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('source_type', 30);
            $table->string('processing_status', 30)->default('pending')->index();
            $table->timestamps();

            $table->index(['website_project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_sources');
    }
};
