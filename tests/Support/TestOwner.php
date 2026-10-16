<?php

namespace Odden\SesTenants\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Odden\SesTenants\Contracts\SesOwner;

class TestOwner extends Model implements SesOwner
{
    protected $table = 'test_owners';

    protected $guarded = [];

    public bool $risky = false;

    public function sesTenantName(): string
    {
        return 'owner-'.$this->getKey();
    }

    public function sesIsRisky(): bool
    {
        return $this->risky;
    }
}
