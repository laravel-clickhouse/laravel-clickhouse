<?php

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration
{
    protected ?string $connection = 'sqlite';

    public function up(): void
    {
        Schema::create('sq_users', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::drop('sq_users');
    }
};
