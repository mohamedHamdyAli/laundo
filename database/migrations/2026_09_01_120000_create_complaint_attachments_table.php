<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «المرفقات (اختياري) — أرفق صورًا توضح المشكلة بوضوح».
 *
 * The complaint form has carried a photo attacher since the first design and the
 * endpoint accepted text only. For the complaints this feature exists for — a
 * stain that did not come out, a torn seam, the wrong garment returned — a
 * photograph is most of the evidence, and describing it in words is what turns a
 * five-minute decision into a phone call.
 *
 * A table rather than a JSON column, matching `order_media`: the dashboard needs
 * to count and list them, and rows are what a paginated screen can join to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->string('path');
            // Who added it. Operations may attach the photo the customer sent by
            // another route, and a complaint's evidence should say where it came
            // from.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('complaint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_attachments');
    }
};
