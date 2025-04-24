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
        Schema::create('router_os', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();

            $table->text('ip_address')->nullable();
            $table->string('login')->nullable();

            $table->string('identity')->nullable();
            $table->string('password')->nullable();
            $table->boolean('connect')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('router_os');
    }
};
