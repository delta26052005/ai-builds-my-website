<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('country', 120);
            $table->string('business_category', 120);
            $table->text('business_description')->nullable();
            $table->string('business_email')->nullable();
            $table->string('business_phone', 30)->nullable();
            $table->string('business_address', 500)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('google_business_url', 2048)->nullable();
            $table->json('social_links')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_projects');
    }
};
