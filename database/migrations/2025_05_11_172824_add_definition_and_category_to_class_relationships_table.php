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
        Schema::table('class_relationships', function (Blueprint $table) {
            $table->text('definition')->nullable();
            $table->string('relationship_category')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_relationships', function (Blueprint $table) {
            $table->dropColumn(['definition', 'relationship_category']);
        });
    }
};
