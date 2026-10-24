<?php

namespace App\Http\Controllers;

use App\Services\VenueEnquiryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Public, host-bound enquiry intake. Never creates a booking, hold or charge. */
final class PublicEnquiryController
{
    public function __invoke(
        Request $request, PublicSiteRequestGate $gate, VenueEnquiryService $service,
    ) {
        $context = $gate->authorize($request);
        // Hidden honeypot: bots that fill it are rejected without persistence.
        abort_if($request->filled('company_website'), 422);

        $validated = $request->validate([
            'request_key' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
            'hall_id' => ['nullable', 'uuid'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'program' => ['required', 'in:morning,noon,evening'],
            'guests' => ['required', 'integer', 'min:1', 'max:100000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ]);
        if (($validated['email'] ?? '') === '' && ($validated['phone'] ?? '') === '') {
            throw ValidationException::withMessages([
                'email' => 'Provide an email address or phone number so the venue can reply.',
            ]);
        }

        try {
            $service->submit($context['companyId'], $context['websiteId'], [
                'requestKey' => $validated['request_key'],
                'customerName' => $validated['customer_name'],
                'email' => $validated['email'] ?? '',
                'phone' => $validated['phone'] ?? '',
                'hallId' => $validated['hall_id'] ?? null,
                'eventDate' => $validated['event_date'],
                'program' => $validated['program'],
                'guests' => $validated['guests'],
                'message' => $validated['message'] ?? '',
                'consent' => true,
            ], $request->ip());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['enquiry' => $exception->getMessage()]);
        }

        return redirect('/')->with(
            'enquiry_success',
            'Thank you. Your enquiry has been sent privately to the venue; it is not a confirmed booking.',
        );
    }
}
