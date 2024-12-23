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
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('class')->comment('プラグインクラス名');
            $table->string('version')->nullable()->comment('プラグインバージョン');
            $table->boolean('active')->default(false)->comment('プラグインのアクティベーション');
            $table->enum('migrate_status', ['pending', 'success', 'failed', 'rollback'])->default('pending')->comment('マイグレーションステータス');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
