<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_fact_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_fact_id')->constrained()->cascadeOnDelete();
            $table->longText('candidate_value');
            $table->char('value_hash', 64);
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->string('verification_status', 30)->default('pending')->index();
            $table->boolean('requires_clarification')->default(false);
            $table->string('clarification_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['business_fact_id', 'value_hash']);
            $table->index(['business_fact_id', 'verification_status'], 'fact_candidates_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_fact_candidates');
    }
};
