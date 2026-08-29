<?php

use App\Enums\AiCapabilityPurpose;
use App\Models\AiCapability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per AI function the app needs. No credentials here — accounts
     * carry those. The three rows are fixed: code references the keys as
     * constants, so rows are seeded (not runtime-created) and accounts are
     * added underneath.
     */
    public function up(): void
    {
        Schema::create('ai_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('purpose')->default(AiCapabilityPurpose::Text->value);
            // Default off: a freshly seeded capability must not start calling anything.
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        foreach ([
            ['key' => 'criteria_generation', 'label' => 'Approval criteria generation', 'purpose' => AiCapabilityPurpose::Text->value],
            ['key' => 'criteria_screening', 'label' => 'Approval criteria screening', 'purpose' => AiCapabilityPurpose::Text->value],
            ['key' => 'proof_moderation', 'label' => 'Proof moderation', 'purpose' => AiCapabilityPurpose::Vision->value],
        ] as $capability) {
            AiCapability::query()->firstOrCreate(
                ['key' => $capability['key']],
                $capability + ['is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_capabilities');
    }
};
