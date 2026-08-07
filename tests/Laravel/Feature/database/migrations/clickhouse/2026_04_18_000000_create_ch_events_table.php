<?php

use ClickHouse\Laravel\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::create('ch_events', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->string('name');
            $table->engine('Memory');
        });
    }

    public function down(): void
    {
        Schema::dropIfExistsSync('ch_events');
    }
};
