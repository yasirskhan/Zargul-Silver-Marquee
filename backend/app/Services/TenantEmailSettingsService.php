<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Owner-managed SMTP settings for venue invoice and booking email. */
final class TenantEmailSettingsService
{
    public function state(User $actor,string $companyId):array
    {
        $this->authorize($actor,$companyId);
        $row=DB::table('tenant_email_settings')->where('company_id',$companyId)->first();
        if(!$row)return ['revision'=>0,'config'=>$this->empty()+['hasPassword'=>false]];
        $config=json_decode($row->config,true,512,JSON_THROW_ON_ERROR);
        $has=is_string($config['password']??null)&&$config['password']!=='';
        unset($config['password']);
        return ['revision'=>(int)$row->revision,'config'=>$config+['hasPassword'=>$has]];
    }

    public function save(User $actor,string $companyId,array $config,int $expectedRevision):array
    {
        $this->authorize($actor,$companyId);
        $clean=$this->validate($config);
        return DB::transaction(function()use($actor,$companyId,$clean,$expectedRevision):array{
            $current=DB::table('tenant_email_settings')->where('company_id',$companyId)->lockForUpdate()->first();
            $live=$current?(int)$current->revision:0;
            if($live!==$expectedRevision)throw new ConflictHttpException('Email settings changed; reload before saving.');
            $previous=$current?json_decode($current->config,true,512,JSON_THROW_ON_ERROR):[];
            if($clean['password']==='')$clean['password']=$previous['password']??'';
            if($clean['password']!==''&&!str_starts_with($clean['password'],'eyJ'))$clean['password']=Crypt::encryptString($clean['password']);
            $stored=$clean; unset($stored['password']);
            $stored['password']=$clean['password'];
            $next=$live+1;
            if($current)DB::table('tenant_email_settings')->where('company_id',$companyId)->update([
                'config'=>json_encode($stored,JSON_THROW_ON_ERROR),'revision'=>$next,'updated_by'=>$actor->getKey(),'updated_at'=>now()]);
            else DB::table('tenant_email_settings')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'config'=>json_encode($stored,JSON_THROW_ON_ERROR),'revision'=>$next,'updated_by'=>$actor->getKey(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                'action'=>'tenant.email_settings_updated','entity_type'=>'tenant_email_settings','entity_id'=>$companyId,
                'before_values'=>null,'after_values'=>json_encode(['revision'=>$next,'host'=>$clean['host'],'fromAddress'=>$clean['fromAddress']],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['passwordStoredEncrypted'=>true],JSON_THROW_ON_ERROR)]);
            return ['revision'=>$next];
        },3);
    }

    public function sendTest(User $actor,string $companyId,string $to):void
    {
        $this->authorize($actor,$companyId);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Valid test recipient required.');
        $this->send($companyId,$to,'Zargul SMTP test','Your Zargul SMTP settings are working.');
    }

    public function sendInvoice(User $actor,string $companyId,string $invoiceId):bool
    {
        if(!$this->authorize($actor,$companyId,false))return false;
        return $this->sendInvoiceForCompany($companyId,$invoiceId);
    }

    public function sendInvoiceForCompany(string $companyId,string $invoiceId):bool
    {
        $invoice=DB::table('venue_invoices')->where('company_id',$companyId)->where('id',$invoiceId)->first();
        if(!$invoice)return false;
        $customer=DB::table('customer_profiles')->where('company_id',$companyId)->where('id',$invoice->customer_id)->first();
        if(!$customer||!$customer->email_ciphertext)return false;
        try{$email=Crypt::decryptString($customer->email_ciphertext);}catch(\Throwable){return false;}
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
        $paid=(int)DB::table('venue_invoice_payments')->where('company_id',$companyId)->where('invoice_id',$invoiceId)->sum('amount_minor');
        $refunded=(int)DB::table('venue_invoice_refunds')->where('company_id',$companyId)->where('invoice_id',$invoiceId)->sum('amount_minor');
        $due=max(0,(int)$invoice->total_minor-($paid-$refunded));
        $net=max(0,$paid-$refunded);
        $body="Invoice {$invoice->invoice_number}\nTotal: ".number_format($invoice->total_minor/100,2)." {$invoice->currency_code}"
            ."\nPayments received: ".number_format($paid/100,2)." {$invoice->currency_code}"
            ."\nRefunds: ".number_format($refunded/100,2)." {$invoice->currency_code}"
            ."\nNet paid: ".number_format($net/100,2)." {$invoice->currency_code}"
            ."\nOutstanding: ".number_format($due/100,2)." {$invoice->currency_code}\n\nZargul Silver Marquee";
        return $this->send($companyId,$email,'Invoice '.$invoice->invoice_number,$body);
    }

    private function send(string $companyId,string $to,string $subject,string $body):bool
    {
        $row=DB::table('tenant_email_settings')->where('company_id',$companyId)->first();
        if(!$row)return false;
        $c=json_decode($row->config,true,512,JSON_THROW_ON_ERROR);
        try{$password=$c['password']===''?'':Crypt::decryptString($c['password']);}catch(\Throwable){return false;}
        config(['mail.mailers.tenant_smtp'=>['transport'=>'smtp','host'=>$c['host'],'port'=>(int)$c['port'],
            'username'=>$c['username']?:null,'password'=>$password?:null,'encryption'=>$c['encryption']?:null,
            'timeout'=>10]]);
        config(['mail.from'=>['address'=>$c['fromAddress'],'name'=>$c['fromName']]]);
        Mail::purge('tenant_smtp');
        try{Mail::mailer('tenant_smtp')->raw($body,fn($m)=>$m->to($to)->subject($subject));return true;}
        catch(\Throwable){return false;}
    }

    private function authorize(User $actor,string $companyId,bool $throw=true):bool
    {
        $ok=$actor->is_active&&$actor->hasVerifiedEmail()&&DB::table('company_memberships')->where('company_id',$companyId)
            ->where('user_id',$actor->getKey())->where('role','owner')->where('is_active',true)->exists();
        if(!$ok&&$throw)throw new AuthorizationException('Only an active verified owner can configure email.');
        return $ok;
    }

    private function empty():array{return ['host'=>'','port'=>587,'username'=>'','password'=>'','encryption'=>'tls','fromAddress'=>'','fromName'=>''];}

    private function validate(array $c):array
    {
        $host=trim((string)($c['host']??''));$port=(int)($c['port']??0);
        $username=trim((string)($c['username']??''));$password=(string)($c['password']??'');
        $encryption=trim((string)($c['encryption']??'tls'));$from=trim((string)($c['fromAddress']??''));$name=trim((string)($c['fromName']??''));
        if($host===''||mb_strlen($host)>253||$port<1||$port>65535||!in_array($encryption,['tls','ssl',''],true)
            ||!filter_var($from,FILTER_VALIDATE_EMAIL)||mb_strlen($name)>120
            ||preg_match('/[<>[:cntrl:]]/u',$host.$username.$name))throw new InvalidArgumentException('Valid SMTP host, port, encryption and From address are required.');
        return ['host'=>$host,'port'=>$port,'username'=>$username,'password'=>$password,
            'encryption'=>$encryption,'fromAddress'=>$from,'fromName'=>$name];
    }
}
