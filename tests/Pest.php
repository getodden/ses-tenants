<?php

use Odden\SesTenants\Tests\PersistentTestCase;
use Odden\SesTenants\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(PersistentTestCase::class)->in('Persistence');
