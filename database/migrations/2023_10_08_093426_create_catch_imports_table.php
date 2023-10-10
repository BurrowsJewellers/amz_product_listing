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
        Schema::create('catch_imports', function (Blueprint $table) {
            $table->id();
            $table->string('import_type');
            $table->integer('import_id')->nullable();
            $table->integer('product_import_id')->nullable();
            $table->string('file_name')->nullable();
            $table->string('response_file_name')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catch_imports');
    }
};
