<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CentralSupportOutboxReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CentralSupportOutboxReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unenrolled_user_cannot_enumerate_private_support_request_subjects():void
    {
        $this->expectException(AuthorizationException::class);
        app(CentralSupportOutboxReader::class)
            ->list(new User(),(string)Str::uuid());
    }

    public function test_bounded_history_limit_is_validated_without_query():void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CentralSupportOutboxReader::class)
            ->list(new User(),(string)Str::uuid(),999);
    }
}
