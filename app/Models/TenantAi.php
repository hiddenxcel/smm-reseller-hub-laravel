<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A reseller's own DeepSeek key, and what it has been used for.
 *
 * The key is theirs: DeepSeek bills them directly for every answer their bot
 * gives, and we never see that invoice. The counters here are the only way
 * they can connect a bill to their bot's behaviour.
 */
class TenantAi extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_ai';

    protected $fillable = [
        'tenant_id',
        'deepseek_api_key_enc',
        'status',
        // Written by recordAnswer(), which goes through the query builder —
        // but left assignable so a correction or a backfill is not silently
        // dropped by mass-assignment protection.
        'answers_today',
        'answers_total',
        'counting_day',
    ];

    protected $hidden = [
        'deepseek_api_key_enc',
    ];

    protected function casts(): array
    {
        return [
            'deepseek_api_key_enc' => 'encrypted',
            'counting_day' => 'date',
        ];
    }

    /** Configured to answer: a key is stored and the reseller has not paused it. */
    public function isReady(): bool
    {
        return $this->status === 'active' && filled($this->deepseek_api_key_enc);
    }

    /**
     * Record that an answer was given.
     *
     * Counted after the answer rather than before, so a DeepSeek call that
     * failed is not billed to the reseller's own numbers — they were not
     * charged for it either.
     *
     * The daily figure rolls over on the first answer of a new day. Doing it
     * here rather than in a scheduled job means it cannot quietly stop
     * happening when cron does, and a reseller reading "12 today" is never
     * reading yesterday's total.
     *
     * Written with raw expressions in one statement so two bots answering at
     * once cannot lose a count between a read and a write.
     */
    public function recordAnswer(): void
    {
        $today = Carbon::today()->toDateString();
        $isNewDay = $this->counting_day?->toDateString() !== $today;

        static::withoutTenantScope()
            ->whereKey($this->getKey())
            ->update([
                'answers_today' => $isNewDay ? 1 : DB::raw('answers_today + 1'),
                'answers_total' => DB::raw('answers_total + 1'),
                'counting_day' => $today,
                'updated_at' => now(),
            ]);
    }

    /**
     * Today's count, as a reseller should read it.
     *
     * A stored figure from an earlier day is stale — it belongs to that day,
     * not to this one — so it reads as zero until the next answer rolls it
     * over. Without this a dashboard opened at midnight shows yesterday's
     * usage as though it were today's.
     */
    public function answersToday(): int
    {
        return $this->counting_day?->isToday() ? (int) $this->answers_today : 0;
    }

    /** The row for a tenant, whether or not they have ever configured AI. */
    public static function forTenant(int $tenantId): ?self
    {
        return static::withoutTenantScope()->where('tenant_id', $tenantId)->first();
    }
}
