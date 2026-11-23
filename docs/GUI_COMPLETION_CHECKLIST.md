# Approved GUI completion checklist

Status legend: **B** backend/service functional, **F** existing functional form/table, **V** approved visual layout complete, **A** browser acceptance recorded. This checklist is deliberately conservative; a screen is not marked complete without V and A.

| Approved area | Real route | B | F | V | A | Remaining difference |
|---|---|---|---|---|---|---|
| Business overview | `/admin/overview` | yes | yes | yes | desktop/mobile | none recorded |
| Hall calendar | `/admin/hall-calendar` | yes | yes | day tiles + table | pending final | mock month grid not complete |
| Booking proposals | `/admin/booking-proposals` | yes | yes | pagehead/table | pending | mock filters/dialogs |
| Reservations | `/admin/venue-reservations` | yes | yes | table workspace | targeted tests | detail panel parity |
| Customers | reservation history / quick search | yes | partial | partial | no | no standalone customer directory |
| Finance review | `/admin/finance-desk` | yes | yes | yes | PostgreSQL regressions | mock analytics parity not complete |
| Invoice panel | reservation detail | yes | yes | partial | tests | mock invoice detail layout |
| Approvals | `/admin/approval-center` | yes | yes | pagehead | pending | delegation/dialog parity |
| Approval settings | `/admin/approval-settings` | yes | yes | partial | limits saved | Telegram layout |
| Inventory | `/admin/hall-inventory` | yes | yes | pagehead | phase12 | mock purchasing/transfer tabs |
| Staff | `/admin/staff-management` | yes | yes | pagehead | pending | role matrix/dialogs |
| Website CMS | `/admin/website-workspace` | yes | yes | partial | editor e2e | gallery/media parity |
| Hall programs | `/admin/hall-programs` | yes | yes | partial | pending | mock program cards |
| Pricing tiers | `/admin/hall-pricing-tiers` | yes | yes | partial | pending | package/price dialogs |
| Hall photos | `/admin/hall-photos` | yes | yes | partial | pending | media manager layout |
| Payment settings | `/admin/payment-settings` | yes | yes | partial | pending | provider card layout |
| Email settings | `/admin/email-settings` | yes | yes | partial | tests | delivery log layout |
| Security | `/admin/security-settings` | yes | yes | partial | trusted flow | mock policy cards |
| Licensing | `/admin/licensing` | yes | yes | partial | pending | license timeline layout |
| Audit | `/admin/audit-history` | yes | yes | partial | pending | filter/export parity |
| Support | `/admin/support-inbox` | yes | yes | partial | pending | threaded inbox layout |
| Event operations | `/admin/event-operations` | yes | yes | partial | pending | mock checklist layout |
| Setup wizard | `/admin/venue-setup` | yes | yes | two-column shell | wizard spec | field-group parity |
| Installer start | `/install` | yes | yes | partial | tests | approved installer shell |
| Public homepage | published host | yes | CMS | hero image | pending | section-level mock parity |
| Public booking | `/book` | yes | yes | approved shell | desktop/mobile | later-step visual parity |
| Central dashboard | `/staff/home` | yes | existing | sidebar shell | central tests | inner page layouts |
| Central customers | `/staff/customers/directory` | yes | existing | partial | central tests | customer detail parity |
| Central plans | `/staff/plans` | yes | existing | partial | central tests | plan editor parity |
| Central staff/audit | `/staff/staff`, `/staff/audit` | yes | existing | partial | central tests | permission matrix parity |

## Honest overall position

Backend workflows, authorization, financial controls and the principal public booking, VenueDesk dashboard, reservations, calendar, wizard shell and Central navigation are implemented. The detailed screen-by-screen mock parity and full fresh-database integrated browser journey remain incomplete.
