<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Converts a verified public booking into the controlled internal finance chain. */
final class PublicBookingFinanceBridge
{
    public function __construct(private readonly TenantAccess $access) {}

    private static function id(string $confirmationId, string $kind): string
    {
        $h = hash('sha256', 'public-booking-finance|'.$kind.'|'.$confirmationId, true);
        $h[6] = chr((ord($h[6]) & 15) | 64);
        $h[8] = chr((ord($h[8]) & 63) | 128);
        $x = bin2hex(substr($h, 0, 16));

        return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'
            .substr($x,16,4).'-'.substr($x,20);
    }

    public static function invoiceIdFor(string $confirmationId): string
    {
        return self::id($confirmationId,'invoice');
    }
    /** @return array<string,mixed> */
    public function connect(User $actor, string $companyId, string $confirmationId): array
    {
        if (! Str::isUuid($companyId) || ! Str::isUuid($confirmationId)
            || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()) {
            throw new InvalidArgumentException('Invalid booking finance request.');
        }
        $confirmation = DB::table('public_booking_confirmations')
            ->where('company_id', $companyId)->where('id', $confirmationId)->first();
        if (! $confirmation) {
            throw new AuthorizationException('Public booking is not in this company.');
        }
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $confirmation->hall_id)->where('is_active', true)->first();
        if (! $hall || ! $this->access->canManageCustomerProfiles($actor, $companyId)
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current owner/admin booking finance authority required.');
        }
        $draftId = self::id($confirmationId, 'draft');
        $customerId = self::id($confirmationId, 'customer');
        $reservationId = self::id($confirmationId, 'reservation');
        $claimId = self::id($confirmationId, 'claim');
        $quoteId = self::id($confirmationId, 'quote');
        $issueId = self::id($confirmationId, 'issue');
        $invoiceId = self::id($confirmationId, 'invoice');
        $paymentId = self::id($confirmationId, 'payment');

        return DB::transaction(function () use (
            $actor, $companyId, $confirmation, $hall, $customerId, $draftId,
            $reservationId, $claimId, $quoteId, $issueId, $invoiceId, $paymentId
        ): array {
            $existingInvoice = DB::table('venue_invoices')->where('company_id',$companyId)
                ->where('id',$invoiceId)->first();
            if ($existingInvoice) {
                $summary = app(VenueInvoicePaymentService::class)->history(
                    $actor,$companyId,$invoiceId);

                return ['reservationId'=>$existingInvoice->reservation_id,
                    'invoiceId'=>$invoiceId,'paymentId'=>$paymentId,
                    'invoiceNumber'=>$existingInvoice->invoice_number,
                    'summary'=>$summary,'created'=>false];
            }
            if (! DB::table('customer_profiles')->where('company_id',$companyId)
                ->where('id',$customerId)->exists()) {
                DB::table('customer_profiles')->insert([
                    'id'=>$customerId,'company_id'=>$companyId,
                    'name_ciphertext'=>Crypt::encryptString($confirmation->customer_name),
                    'email_ciphertext'=>$confirmation->customer_email
                        ? Crypt::encryptString($confirmation->customer_email) : null,
                    'phone_ciphertext'=>$confirmation->customer_phone
                        ? Crypt::encryptString($confirmation->customer_phone) : null,
                    'revision'=>1,'is_active'=>true,'created_by'=>$actor->getKey(),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            if (! DB::table('booking_drafts')->where('company_id',$companyId)
                ->where('id',$draftId)->exists()) {
                DB::table('booking_drafts')->insert([
                    'id'=>$draftId,'company_id'=>$companyId,'hall_id'=>$hall->id,
                    'customer_id'=>$customerId,'event_date'=>$confirmation->event_date,
                    'program'=>$confirmation->program,'requested_guests'=>$confirmation->guests,
                    'status'=>'under_review','revision'=>1,'created_by'=>$actor->getKey(),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $reservation = DB::table('venue_reservations')->where('company_id',$companyId)
                ->where('id',$reservationId)->first();
            if (!$reservation) {
                if (!DB::table('hall_slot_claims')->where('company_id',$companyId)
                    ->where('hall_id',$hall->id)->where('event_date',$confirmation->event_date)
                    ->where('program',$confirmation->program)->exists()) {
                    DB::table('hall_slot_claims')->insert([
                        'id'=>$claimId,'company_id'=>$companyId,'hall_id'=>$hall->id,
                        'event_date'=>$confirmation->event_date,'program'=>$confirmation->program,
                        'status'=>'confirmed','expires_at'=>null,'created_by'=>$actor->getKey(),
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                DB::table('venue_reservations')->insert([
                    'id'=>$reservationId,'company_id'=>$companyId,'source_draft_id'=>$draftId,
                    'customer_id'=>$customerId,'hall_id'=>$hall->id,'claim_id'=>$claimId,
                    'event_date'=>$confirmation->event_date,'program'=>$confirmation->program,
                    'guests'=>$confirmation->guests,'status'=>'confirmed','revision'=>1,
                    'created_by'=>$actor->getKey(),'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('booking_drafts')->where('id',$draftId)->update([
                    'status'=>'booked','revision'=>2,'updated_at'=>now(),
                ]);
                DB::table('venue_reservation_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'reservation_id'=>$reservationId,'revision'=>1,'action'=>'confirmed',
                    'before_values'=>json_encode(null),'after_values'=>json_encode([
                        'status'=>'confirmed','sourcePublicBooking'=>$confirmation->id,
                        'hallId'=>$hall->id,'date'=>$confirmation->event_date,
                        'program'=>$confirmation->program,'guests'=>(int)$confirmation->guests,
                    ],JSON_THROW_ON_ERROR),'actor_id'=>$actor->getKey(),
                    'reason'=>'Confirmed public booking converted to reservation',
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'reservation.confirmed','entity_type'=>'venue_reservation',
                    'entity_id'=>$reservationId,'before_values'=>null,
                    'after_values'=>json_encode(['publicBookingId'=>$confirmation->id,
                        'status'=>'confirmed','hallId'=>$hall->id],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['reason'=>'Public booking finance bridge'],JSON_THROW_ON_ERROR),
                ]);
                $reservation = DB::table('venue_reservations')->where('company_id',$companyId)
                    ->where('id',$reservationId)->first();
            }
            if (!$reservation) throw new ConflictHttpException('Reservation conversion failed.');
            $publicQuote=json_decode($confirmation->quote,true,64,JSON_THROW_ON_ERROR);
            $publicHall=null;
            foreach(($publicQuote['halls']??[]) as $candidate){
                if(($candidate['hallId']??null)===$hall->id){$publicHall=$candidate;break;}
            }
            if(!$publicHall){throw new ConflictHttpException('Frozen public quote is unavailable.');}
            $frozenLines=json_encode($publicHall['lines']??[],JSON_THROW_ON_ERROR);
            $quoteNumber=1+(int)DB::table('venue_quote_issues')->where('company_id',$companyId)->max('number');
            $quoteDoc='Q-'.str_pad((string)$quoteNumber,9,'0',STR_PAD_LEFT);
            DB::table('venue_quote_snapshots')->insert([
                'id'=>$quoteId,'company_id'=>$companyId,'reservation_id'=>$reservationId,
                'reservation_revision'=>1,'hall_id'=>$hall->id,
                'program_revision'=>(int)$hall->program_rules_revision,
                'seating_revision'=>(int)$hall->seating_price_revision,
                'addon_revision'=>(int)$hall->addon_catalog_revision,
                'currency_code'=>$confirmation->currency_code,'frozen_lines'=>$frozenLines,
                'request_fingerprint'=>hash('sha256','public-booking|'.$confirmation->id),
                'subtotal_minor'=>(int)$confirmation->total_minor,
                'status'=>'internal_nonbinding','created_by'=>$actor->getKey(),
                'reason'=>'Frozen public booking quote '.$confirmation->id,
            ]);
            DB::table('venue_quote_issues')->insert([
                'id'=>$issueId,'company_id'=>$companyId,'snapshot_id'=>$quoteId,
                'reservation_id'=>$reservationId,'number'=>$quoteNumber,
                'document_number'=>$quoteDoc,'currency_code'=>$confirmation->currency_code,
                'subtotal_minor'=>(int)$confirmation->total_minor,'frozen_lines'=>$frozenLines,
                'status'=>'internal_issued','purpose'=>'Public booking '.$confirmation->id.' quote issue',
                'issued_by'=>$actor->getKey(),'issued_at'=>now(),
            ]);
            $invoiceNumber=1+(int)DB::table('venue_invoices')->where('company_id',$companyId)->max('number');
            $invoiceDoc='INV-'.str_pad((string)$invoiceNumber,9,'0',STR_PAD_LEFT);
            DB::table('venue_invoices')->insert([
                'id'=>$invoiceId,'company_id'=>$companyId,'quote_issue_id'=>$issueId,
                'reservation_id'=>$reservationId,'customer_id'=>$customerId,
                'number'=>$invoiceNumber,'invoice_number'=>$invoiceDoc,
                'tax_rate_bps'=>0,'currency_code'=>$confirmation->currency_code,
                'frozen_lines'=>$frozenLines,'subtotal_minor'=>(int)$confirmation->total_minor,
                'tax_minor'=>0,'total_minor'=>(int)$confirmation->total_minor,
                'reason'=>'Invoice for confirmed public booking '.$confirmation->id,
                'issued_by'=>$actor->getKey(),'issued_at'=>now(),
            ]);
            foreach([['venue_quote.snapshot_created','venue_quote_snapshot',$quoteId,
                      ['subtotalMinor'=>(int)$confirmation->total_minor]],
                     ['venue_quote.internal_issued','venue_quote_issue',$issueId,
                      ['documentNumber'=>$quoteDoc]],
                     ['venue_invoice.issued','venue_invoice',$invoiceId,
                      ['invoiceNumber'=>$invoiceDoc,'totalMinor'=>(int)$confirmation->total_minor]]] as [$action,$type,$id,$after]){
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,
                    'before_values'=>null,'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['publicBookingId'=>$confirmation->id],JSON_THROW_ON_ERROR),
                ]);
            }            $invoiceForPayment=DB::table('venue_invoices')->where('company_id',$companyId)
                ->where('id',$invoiceId)->first(['total_minor','currency_code']);
            if(!$invoiceForPayment){
                throw new ConflictHttpException('Invoice was not created.');
            }
            if((int)$confirmation->deposit_minor > (int)$invoiceForPayment->total_minor){
                throw new ConflictHttpException('Deposit '.(int)$confirmation->deposit_minor
                    .' exceeds invoice total '.(int)$invoiceForPayment->total_minor.'.');
            }            app(VenueInvoicePaymentService::class)->record(
                $actor,$companyId,$invoiceId,$paymentId,'bank',
                (int)$confirmation->deposit_minor,'PUBLIC-'.$confirmation->id,
                'Verified synthetic public booking deposit'
            );
            $invoice = DB::table('venue_invoices')->where('company_id',$companyId)
                ->where('id',$invoiceId)->first();
            $summary = app(VenueInvoicePaymentService::class)->history(
                $actor,$companyId,$invoiceId);

            return ['reservationId'=>$reservationId,'invoiceId'=>$invoiceId,
                'paymentId'=>$paymentId,'invoiceNumber'=>$invoice->invoice_number,
                'summary'=>$summary,'created'=>true];
        }, 3);
    }
}
