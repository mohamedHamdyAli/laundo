<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the Category module.
 *
 * It had five working screens, routes, permissions and entries in
 * `config/menu.php`'s icons/titles/routes maps — but never appeared in `groups`
 * or `singles`, so `MenuBuilder` never rendered it and nobody could reach any
 * of it except by typing the URL. The table held no rows, nothing referenced
 * the model, and the catalogue's `item_category` covers the same ground: an
 * `Item` belongs to an `ItemCategory`, never to one of these.
 *
 * The original create migration is left in place — it has already run
 * everywhere, and rewriting history would leave any environment that skipped
 * this one unable to migrate. This drops the table forward instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('categories');
    }

    /**
     * Rebuilt to its shape at the time of removal, so `migrate:rollback` is not
     * a dead end — though the module it belonged to is gone.
     */
    public function down(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->text('name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('default_price', 10, 2)->nullable();
            $table->string('image')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }
};
