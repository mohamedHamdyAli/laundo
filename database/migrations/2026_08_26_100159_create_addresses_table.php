<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer addresses.
 *
 * Every column corresponds to a field in the design's "إضافة عنوان جديد" screen —
 * nothing here is invented:
 *
 *   label          اسم العنوان (المنزل / العمل / منزل العائلة / عنوان آخر)
 *   city_id        المدينة
 *   zone_id        المنطقة
 *   street         العنوان التفصيلي
 *   building       رقم المبنى
 *   floor          الدور
 *   apartment      رقم الشقة
 *   landmark       علامة مميزة (اختياري)
 *   notes          ملاحظات العنوان (اختياري)
 *   contact_phone  رقم الهاتف للتواصل — null means "استخدام رقم الحساب"
 *   lat / lng      the map pin, required by business decision
 *   is_default     تعيين كعنوان افتراضي
 *
 * `zone_id` is restrictOnDelete rather than cascade: silently deleting a
 * customer's address because an admin tidied up a zone would lose data the
 * customer entered. The delete should fail and be dealt with deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('label')->nullable();

            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('zones')->restrictOnDelete();

            $table->string('street');
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->string('apartment')->nullable();
            $table->string('landmark')->nullable();
            $table->text('notes')->nullable();
            $table->string('contact_phone')->nullable();

            // 7 decimal places is roughly a centimetre — ample for a door pin.
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
