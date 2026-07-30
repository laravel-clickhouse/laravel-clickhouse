<?php

use ClickHouse\Hypervel\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    protected ?string $connection = 'clickhouse';

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
