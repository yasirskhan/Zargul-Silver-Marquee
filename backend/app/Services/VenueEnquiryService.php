<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Public website enquiry intake and owner/admin triage. No booking or charge. */
final class VenueEnquiryService
{
    private const STATUSES = ['new', 'contacted', 'closed'];

    public function submit(
        string $companyId, string $websiteId, array $data, ?string $ip = null,
    ): array {
        $website = DB::table('websites')->where('id', $websiteId)
            ->where('company_id', $companyId)->first(['id']);
        if ($website === null) {
            throw new InvalidArgumentException('The published website is unavailable.');
        }
        $requestKey = (string) ($data['requestKey'] ?? '');
        if (! Str::isUuid($requestKey)) {
            throw new InvalidArgumentException('A valid enquiry request key is required.');
        }
        $existing = DB::table('venue_enquiries')->where('request_key', $requestKey)->first();
        if ($existing !== null) {
            if ($existing->company_id !== $companyId || $existing->website_id !== $websiteId) {
                throw new InvalidArgumentException('Enquiry request key is not available.');
            }

            return ['id' => $existing->id, 'status' => $existing->status, 'duplicate' => true];
        }

        $name = trim((string) ($data['customerName'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('A customer name is required.');
        }
        if ($email === '' && $phone === '') {
            throw new InvalidArgumentException('An email address or phone number is required.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is invalid.');
        }
        if (mb_strlen($email) > 254 || mb_strlen($phone) > 32
            || preg_match('/[<>[:cntrl:]]/u', $name.$email.$phone.$message)) {
            throw new InvalidArgumentException('Enquiry contact details are invalid.');
        }
        $date = (string) ($data['eventDate'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date
            || $parsed < new \DateTimeImmutable('today')) {
            throw new InvalidArgumentException('Choose a valid future event date.');
        }
        $program = (string) ($data['program'] ?? '');
        if (! in_array($program, ['morning', 'noon', 'evening'], true)) {
            throw new InvalidArgumentException('Choose a valid event program.');
        }
        $guests = filter_var($data['guests'] ?? null, FILTER_VALIDATE_INT);
        if ($guests === false || $guests < 1 || $guests > 100000) {
            throw new InvalidArgumentException('Guest count must be between 1 and 100000.');
        }
        if (($data['consent'] ?? false) !== true) {
            throw new InvalidArgumentException('Privacy consent is required.');
        }

        $hallId = $data['hallId'] ?? null;
        if ($hallId !== null && $hallId !== '') {
            if (! Str::isUuid($hallId) || ! DB::table('halls')->where('id', $hallId)
                ->where('company_id', $companyId)->where('is_active', true)->exists()) {
                throw new InvalidArgumentException('The selected hall is unavailable.');
            }
        } else {
            $hallId = null;
        }

        return DB::transaction(function () use (
            $companyId, $websiteId, $hallId, $name, $email, $phone, $date,
            $program, $guests, $message, $requestKey, $ip,
        ): array {
            $id = (string) Str::uuid();
            DB::table('venue_enquiries')->insert([
                'id' => $id, 'company_id' => $companyId, 'website_id' => $websiteId,
                'hall_id' => $hallId, 'customer_name' => $name,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'event_date' => $date, 'program' => $program, 'guests' => $guests,
                'message' => $message !== '' ? $message : null,
                'consent_at' => now(), 'status' => 'new', 'source' => 'public_website',
                'request_key' => $requestKey,
                'ip_hash' => $ip !== null ? hash('sha256', $ip) : null,
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => null, 'action' => 'public.enquiry_received',
                'entity_type' => 'venue_enquiry', 'entity_id' => $id,
                'before_values' => null,
                'after_values' => json_encode([
                    'hallId' => $hallId, 'eventDate' => $date,
                    'program' => $program, 'guests' => $guests,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['source' => 'public_website'], JSON_THROW_ON_ERROR),
            ]);

            return ['id' => $id, 'status' => 'new', 'duplicate' => false];
        }, 3);
    }

    public function list(User $actor, string $companyId): array
    {
        $this->authorize($actor, $companyId);

        return DB::table('venue_enquiries')->where('company_id', $companyId)
            ->leftJoin('halls', 'halls.id', '=', 'venue_enquiries.hall_id')
            ->orderByDesc('venue_enquiries.created_at')->limit(100)->get([
                'venue_enquiries.id', 'venue_enquiries.hall_id', 'halls.name as hall_name',
                'venue_enquiries.customer_name', 'venue_enquiries.email',
                'venue_enquiries.phone', 'venue_enquiries.event_date',
                'venue_enquiries.program', 'venue_enquiries.guests',
                'venue_enquiries.message', 'venue_enquiries.status',
                'venue_enquiries.created_at',
            ])->map(fn ($row): array => (array) $row)->all();
    }

    public function updateStatus(
        User $actor, string $companyId, string $enquiryId, string $status, string $reason,
    ): void {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($enquiryId) || ! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid enquiry status request.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('A triage reason is required.');
        }
        DB::transaction(function () use ($actor, $companyId, $enquiryId, $status, $reason): void {
            $before = DB::table('venue_enquiries')->where('id', $enquiryId)
                ->where('company_id', $companyId)->lockForUpdate()->first();
            if ($before === null) {
                throw new AuthorizationException('Enquiry is not accessible.');
            }
            DB::table('venue_enquiries')->where('id', $enquiryId)
                ->where('company_id', $companyId)
                ->update(['status' => $status, 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'public_enquiry.status_updated',
                'entity_type' => 'venue_enquiry', 'entity_id' => $enquiryId,
                'before_values' => json_encode(['status' => $before->status], JSON_THROW_ON_ERROR),
                'after_values' => json_encode(['status' => $status], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
        }, 3);
    }

    private function authorize(User $actor, string $companyId): void
    {
        $membership = DB::table('company_memberships')->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])->first(['company_id']);
        $allowed = $actor->is_active && $actor->hasVerifiedEmail()
            && DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            && $membership !== null
            && DB::table('companies')->where('id', $companyId)
                ->where('is_active', true)->exists();
        if (! $allowed) {
            throw new AuthorizationException('You cannot access venue enquiries.');
        }
    }
}
