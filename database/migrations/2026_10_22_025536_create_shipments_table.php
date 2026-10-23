<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('shipment_number', 50)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->date('scheduled_on')->nullable();
            $table->date('shipped_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('shipped_at', 6)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['status', 'scheduled_on']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 19, 4);
            $table->timestamps(6);
            $table->unique(['shipment_id', 'order_line_id']);
            $table->index('order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
    }
};
