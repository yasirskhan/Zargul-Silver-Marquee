<?php
declare(strict_types=1);

$dsn=getenv('CENTRAL_DB_DSN');
if(!is_string($dsn)||!str_contains($dsn,'hafzatech_central_test')){fwrite(STDERR,"isolated DB required\n");exit(1);}
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer='11111111-1111-4111-8111-111111111111';
$foreign='22222222-2222-4222-8222-222222222222';
$enrollment='33333333-3333-4333-8333-333333333333';
$installation='44444444-4444-4444-8444-444444444444';
$license='55555555-5555-4555-8555-555555555555';
$staff='66666666-6666-4666-8666-666666666666';
$secret='JBSWY3DPEHPK3PXP';
$sql=[
 ["INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,'Synthetic Central Venue','active') ON CONFLICT (id) DO NOTHING",['id'=>$customer]],
 ["INSERT INTO vendor_customers(id,legal_name,status) VALUES(:id,'Foreign Central Venue','active') ON CONFLICT (id) DO NOTHING",['id'=>$foreign]],
 ["INSERT INTO installation_enrollments(id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at) VALUES(:id,:c,:t,'central-browser.example.test',now(),now()+interval '1 day',now()) ON CONFLICT (id) DO NOTHING",['id'=>$enrollment,'c'=>$customer,'t'=>hash('sha256',$enrollment)]],
 ["INSERT INTO registered_installations(id,customer_id,enrollment_id,approved_domain,ed25519_public_key,status) VALUES(:id,:c,:e,'central-browser.example.test',:k,'active') ON CONFLICT (id) DO NOTHING",['id'=>$installation,'c'=>$customer,'e'=>$enrollment,'k'=>rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=')]],
 ["INSERT INTO central_licenses(id,customer_id,installation_id,approved_domain,current_revision) VALUES(:id,:c,:i,'central-browser.example.test',0) ON CONFLICT (id) DO NOTHING",['id'=>$license,'c'=>$customer,'i'=>$installation]],
 ["INSERT INTO central_staff_users(id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status) VALUES(:id,'central-browser@example.test','Central Browser Officer',:p,decode(:s,'hex'),now(),'active') ON CONFLICT (email) DO UPDATE SET password_hash=EXCLUDED.password_hash,mfa_secret_ciphertext=EXCLUDED.mfa_secret_ciphertext",['id'=>$staff,'p'=>password_hash('central-browser-password',PASSWORD_DEFAULT),'s'=>bin2hex($secret)]],
 ["INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason) SELECT :id,:s,'license_officer',:c,'Central browser fixture' WHERE NOT EXISTS (SELECT 1 FROM central_staff_role_grants WHERE staff_id=:s AND role='license_officer' AND revoked_at IS NULL)",['id'=>'77777777-7777-4777-8777-777777777777','s'=>$staff,'c'=>$customer]],
 ["INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason) SELECT :id,:s,'plan_administrator',NULL,'Central browser fixture' WHERE NOT EXISTS (SELECT 1 FROM central_staff_role_grants WHERE staff_id=:s AND role='plan_administrator' AND revoked_at IS NULL)",['id'=>'88888888-8888-4888-8888-888888888888','s'=>$staff]],
 ["INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason) SELECT :id,:s,'staff_administrator',NULL,'Central browser fixture' WHERE NOT EXISTS (SELECT 1 FROM central_staff_role_grants WHERE staff_id=:s AND role='staff_administrator' AND revoked_at IS NULL)",['id'=>'99999999-9999-4999-8999-999999999999','s'=>$staff]],
 ["INSERT INTO central_staff_role_grants(id,staff_id,role,customer_scope_id,reason) SELECT :id,:s,'auditor',NULL,'Central browser fixture' WHERE NOT EXISTS (SELECT 1 FROM central_staff_role_grants WHERE staff_id=:s AND role='auditor' AND revoked_at IS NULL)",['id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','s'=>$staff]],
];
foreach($sql as [$statement,$params]){$db->prepare($statement)->execute($params);}
echo "seeded\n";