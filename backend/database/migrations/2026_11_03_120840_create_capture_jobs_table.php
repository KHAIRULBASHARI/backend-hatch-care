<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capture_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('exposure')->default(500);
            $table->unsignedTinyInteger('gain')->default(0);
            $table->unsignedTinyInteger('quality')->default(6);
            $table->unsignedSmallInteger('settle_ms')->default(250);
            $table->string('image_path')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capture_jobs');
    }
};
