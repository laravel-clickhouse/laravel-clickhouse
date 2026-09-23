<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlite';

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
