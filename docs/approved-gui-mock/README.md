# Zargul approved GUI reference

Snapshot: 2026-11-15
Mock source commit: 6340f27e7a84b026d2bfcd788d6838afa4a2259f (separate mock repository, not the application branch).

This portable archive contains the latest approved interactive UI, including HTML, CSS, JavaScript and local imagery. It does not contain production credentials or the real application backend.

## Open locally

Extract the ZIP, then run from the extracted root:

```sh
python3 -m http.server 8080 --bind 127.0.0.1 --directory dist
```

Open http://127.0.0.1:8080/ in a browser. No ChatGPT account or network connection is required. Use example.test data only.

Direct views:

- Public website: /#public/home
- Public booking: /#public/booking
- VenueDesk: /#venue/dashboard
- Staff permissions: /#venue/staff
- Install wizard demo: /#install/demo
- Payment methods: /#venue/paymentMethods
- Approval delegation: /#venue/approvalSettings
- Telegram configuration: /#venue/telegramSettings
- Security: /#venue/securitySettings
- Mail configuration and invoice outbox: /#venue/emailSettings
- HAFZATECH Central: /#central/dashboard

Demo verification code: 123456. All mail, Telegram, payments and sign-in behavior is simulated; implement actual server-side verification, authorization, storage and delivery using the approved backend and existing safeguards.

Run `node smoke.cjs` from the extracted root to check the mock.

Permanent reference: https://zargul-complete-gui-mock.yasirkhan609335.chatgpt.site/

This is a design and behavior specification for the existing real application's authorized branch `chatgpt/phase0-foundation`. Reuse the real services and preserve all safeguards. Do not deploy the mock instead of the application.
