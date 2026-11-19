<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_code_shipping_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->decimal('shipping_fee', 19, 4);
            $table->decimal('free_shipping_threshold', 19, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('postal_code_shipping_postal_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('postal_code_shipping_group_id');
            $table->foreign('postal_code_shipping_group_id', 'postal_code_shipping_group_fk')->references('id')->on('postal_code_shipping_groups')->cascadeOnDelete();
            $table->char('postal_code', 7)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_code_shipping_postal_codes');
        Schema::dropIfExists('postal_code_shipping_groups');
    }
};
