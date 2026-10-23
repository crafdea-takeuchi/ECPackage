<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['status', 'name']);
        });

        Schema::create('product_attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 50);
            $table->string('input_type', 20)->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps(6);
            $table->unique(['product_group_id', 'code']);
            $table->index(['product_group_id', 'status', 'sort_order'], 'product_attribute_definition_list');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_group_id')->nullable()->after('kind')->constrained()->restrictOnDelete();
            $table->index('product_group_id');
        });

        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_definition_id')->constrained()->restrictOnDelete();
            $table->string('value', 255);
            $table->timestamps(6);
            $table->unique(['product_id', 'product_attribute_definition_id'], 'product_attribute_value_unique');
            $table->index(['product_attribute_definition_id', 'value'], 'product_attribute_value_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_group_id');
        });
        Schema::dropIfExists('product_attribute_definitions');
        Schema::dropIfExists('product_groups');
    }
};
