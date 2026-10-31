<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/InstallationRegistry.php';
require __DIR__.'/../src/DesignerCreditPolicy.php';
use Hafzatech\Central\DesignerCreditPolicy;
function ok(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$enabled=['enabled'=>true,'prefix'=>'Website by','label'=>'HAFZATECH',
    'url'=>'https://hafzatech.com','alignment'=>'center'];
ok(DesignerCreditPolicy::validate($enabled)['enabled']===true,'enabled accepted');
$disabled=['enabled'=>false,'prefix'=>'','label'=>'','url'=>'','alignment'=>'left'];
ok(DesignerCreditPolicy::validate($disabled)['enabled']===false,'disabled accepted');
foreach ([
    ['url'=>'javascript:alert(1)'],
    ['url'=>'https://user:pass@example.test'],
    ['url'=>'https://bad_host.test'],
    ['label'=>"bad\x01label"],
    ['prefix'=>'<script>'],
    ['alignment'=>'justify'],
] as $override){
    $bad=array_replace($enabled,$override);
    try{DesignerCreditPolicy::validate($bad);fwrite(STDERR,'FAIL: invalid accepted '.json_encode($override)."\n");exit(1);}catch(Throwable){}
}
$badDisabled=$disabled;$badDisabled['prefix']='hidden';
try{DesignerCreditPolicy::validate($badDisabled);exit(1);}catch(Throwable){}
echo "designer credit policy OK\n";