<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_fact_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_fact_candidate_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('business_research_source_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('project_source_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('source_type', 30);
            $table->string('source_reference', 255)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('supporting_evidence');
            $table->char('evidence_hash', 64);
            $table->timestamps();

            $table->unique(['business_fact_candidate_id', 'evidence_hash'], 'fact_evidence_candidate_hash_uq');
            $table->index(['business_research_source_id', 'project_source_id'], 'fact_evidence_source_refs_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_fact_evidence');
    }
};
