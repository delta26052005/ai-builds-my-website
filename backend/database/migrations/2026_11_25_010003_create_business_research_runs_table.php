<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedSmallInteger('source_count')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_research_runs');
    }
};
