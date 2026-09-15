<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent it, when a person did.
 *
 * Null for every row written before this and for every automatic message after
 * it, which is the honest value: nobody sent those, the system did. It is only
 * ever filled for a message somebody composed by hand.
 *
 * The column exists because the log's whole purpose is answering «what did we
 * tell them?», and the moment a human can write to a customer the next question
 * is «who?». An audit record that cannot answer that about its one hand-authored
 * row is not an audit record.
 *
 * `nullOnDelete`, matching `user_id`: a moderator who leaves must not take the
 * history of what they sent with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->foreignId('sent_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
        });
    }
};
