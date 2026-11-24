<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_project_id')->constrained()->cascadeOnDelete();
            $table->string('fact_key', 100);
            $table->string('category', 40);
            $table->timestamps();

            $table->unique(['website_project_id', 'fact_key']);
            $table->index(['website_project_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_facts');
    }
};
