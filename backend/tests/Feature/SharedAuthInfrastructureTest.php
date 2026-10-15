<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedAuthInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_cache_supports_persistent_atomic_locks(): void
    {
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $store = Cache::store('database');
        $key = 'mfa-atomic-'.Str::random(12);
        $store->put($key, 'shared-value', 60);
        $this->assertSame('shared-value', $store->get($key));
        $this->assertDatabaseHas('cache', ['key' => config('cache.prefix').$key]);

        $lockKey = 'mfa-replay-'.Str::random(12);
        $held = $store->lock($lockKey, 20);
        $this->assertTrue($held->get());
        $this->assertFalse($store->lock($lockKey, 20)->get());
        $this->assertTrue($held->release());
        $again = $store->lock($lockKey, 20);
        $this->assertTrue($again->get());
        $again->release();
    }

    public function test_database_sessions_are_shared_and_have_expiration_fields(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
        $session = app('session')->driver('database');
        $id = Str::random(40);
        $session->setId($id);
        $session->start();
        $session->put('verified_session', 'test-only');
        $session->save();

        $saved = DB::table('sessions')->where('id', $id)->first();
        $this->assertNotNull($saved);
        $this->assertSame($id, $saved->id);
        $this->assertGreaterThan(0, $saved->last_activity);
        $this->assertNotSame('', $saved->payload);
        $this->assertSame(1, DB::table('sessions')->where('id', $id)->count());
    }
}
