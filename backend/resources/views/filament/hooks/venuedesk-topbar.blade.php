@php
    $nav = [
        ['HallCalendar', 'Calendar'],
        ['BookingProposals', 'Bookings'],
        ['FinanceDesk', 'Finance'],
        ['HallInventory', 'Inventory'],
        ['ApprovalCenter', 'Approvals'],
        ['StaffManagement', 'Staff'],
        ['WebsiteWorkspace', 'Website'],
        ['AuditHistory', 'Audit'],
    ];
@endphp
<div class="vd-commandbar" aria-label="VenueDesk module navigation">
    <div class="vd-commandbar-title">
        <span class="vd-dot"></span>
        <strong>VenueDesk Operations</strong>
        <small>Zargul Silver Marquee · internal operations</small>
    </div>
    <nav class="vd-commandbar-links">
        @foreach ($nav as [$pageClass, $label])
            @php $class = 'App\\Filament\\Pages\\'.$pageClass; @endphp
            @if (class_exists($class) && $class::canAccess())
                <a href="{{ $class::getUrl() }}" >{{ $label }}</a>
            @endif
        @endforeach
    </nav>
</div>
<div class="vd-bottom-nav" aria-label="Mobile VenueDesk navigation">
    @foreach ($nav as [$pageClass, $label])
        @php $class = 'App\\Filament\\Pages\\'.$pageClass; @endphp
        @if (class_exists($class) && $class::canAccess())
            <a href="{{ $class::getUrl() }}" ><span>{{ $loop->iteration }}</span>{{ $label }}</a>
        @endif
    @endforeach
</div>