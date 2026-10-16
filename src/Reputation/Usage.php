<?php

namespace Odden\SesTenants\Reputation;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What an owner has sent and what came of it, counted a day at a time from the application's own sends and from SES's
 * events (never from the provider's bill). Hard bounces only: a soft bounce is SES's to retry.
 */
final class Usage
{
    public const array COLUMNS = ['sent', 'delivered', 'bounced', 'complained'];

    private const string TABLE = 'ses_usage';

    public function record(Model $owner, string $column, int $by = 1): void
    {
        if (! in_array($column, self::COLUMNS, true) || $by < 1) {
            return;
        }

        $day = now()->toDateString();
        $key = ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(), 'day' => $day];

        DB::table(self::TABLE)->insertOrIgnore([...$key, 'created_at' => now(), 'updated_at' => now()]);
        DB::table(self::TABLE)->where($key)->increment($column, $by, ['updated_at' => now()]);
    }

    /**
     * @return array{sent: int, delivered: int, bounced: int, complained: int}
     */
    public function totals(Model $owner, CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        return $this->sum(DB::table(self::TABLE)->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey()), $from, $to);
    }

    /**
     * Every owner together: the account's own standing with SES.
     *
     * @return array{sent: int, delivered: int, bounced: int, complained: int}
     */
    public function accountTotals(CarbonInterface $from): array
    {
        return $this->sum(DB::table(self::TABLE), $from, null);
    }

    /**
     * @return array{sent: int, delivered: int, bounced: int, complained: int}
     */
    private function sum(Builder $query, CarbonInterface $from, ?CarbonInterface $to): array
    {
        $query->where('day', '>=', $from->toDateString());
        $to === null || $query->where('day', '<=', $to->toDateString());

        $row = (array) $query->selectRaw('COALESCE(SUM(sent), 0) as sent, COALESCE(SUM(delivered), 0) as delivered, COALESCE(SUM(bounced), 0) as bounced, COALESCE(SUM(complained), 0) as complained')->first();

        return ['sent' => (int) ($row['sent'] ?? 0), 'delivered' => (int) ($row['delivered'] ?? 0), 'bounced' => (int) ($row['bounced'] ?? 0), 'complained' => (int) ($row['complained'] ?? 0)];
    }
}
