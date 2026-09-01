<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `users.email` nullable.
 *
 * The design marks email optional at registration — «البريد الإلكتروني (اختياري)» —
 * but the original migration declared `string('email')->unique()` with no
 * `nullable()`. Any customer registering without an email hit a NOT NULL
 * violation and got a 500. Phone is the identity in this product; email is a
 * convenience.
 *
 * Uses `->change()`, which Laravel 11+ performs natively on every driver. An
 * earlier version of this migration used a raw MySQL `ALTER` behind a driver
 * guard, on the mistaken belief that column changes still need doctrine/dbal.
 * The cost of that mistake was concrete: on SQLite the column stayed NOT NULL,
 * so the entire test suite could not register a customer.
 *
 * The unique index is kept — MySQL and SQLite both permit many NULLs in one, so
 * several customers can have no email while a supplied one stays unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting while rows hold NULL would fail, and coercing them to empty
        // strings would collide on the unique index. Left as a one-way change.
    }
};
