<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class IsolatedBrowserUploadDiskTest extends TestCase
{
    public function test_real_http_browser_fixture_has_private_local_livewire_temp_disk():void
    {
        $previous=getenv('BROWSER_E2E_SEED');
        $oldConfig=config('filesystems.disks.tmp-for-tests');
        putenv('BROWSER_E2E_SEED=1');
        try {
            (new AppServiceProvider(app()))->register();
            $configured=config('filesystems.disks.tmp-for-tests');
            $this->assertSame('local',$configured['driver']);
            $this->assertSame('private',$configured['visibility']);
            $this->assertStringStartsWith(storage_path('framework/'),$configured['root']);
            $disk=Storage::disk('tmp-for-tests');
            $this->assertTrue($disk->put('livewire-tmp/evidence-probe.txt','private bytes'));
            $this->assertSame('private bytes',$disk->get('livewire-tmp/evidence-probe.txt'));
            $this->assertTrue($disk->delete('livewire-tmp/evidence-probe.txt'));
        } finally {
            Storage::forgetDisk('tmp-for-tests');
            config()->set('filesystems.disks.tmp-for-tests',$oldConfig);
            if($previous===false)putenv('BROWSER_E2E_SEED');
            else putenv('BROWSER_E2E_SEED='.$previous);
        }
    }
}
