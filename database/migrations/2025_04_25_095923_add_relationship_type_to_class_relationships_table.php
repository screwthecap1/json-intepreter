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
            $table->string('relationship_type')->nullable()->after('class2');
        });
    }

    public function down(): void
    {
        Schema::table('class_relationships', function (Blueprint $table) {
            $table->dropColumn('relationship_type');
        });
    }

};
