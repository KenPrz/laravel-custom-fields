<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->foreignId('property_id')->nullable();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
        Schema::dropIfExists('members');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('properties');
    }
};
