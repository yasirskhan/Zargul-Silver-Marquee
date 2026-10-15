import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const file=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
test('public venue site has real responsive semantics and enquiry form',()=>{
 const html=file('index.html');
 for(const part of ['Zargul Silver Marquee','name="viewport"','id="halls"','id="packages"','id="services"','id="gallery"','id="availability"','id="enquiry-form"','assets/site.mjs'])assert.ok(html.includes(part),part);
 assert.ok(/name="date" type="date"/.test(html));assert.ok(/type="submit"/.test(html));
});
test('management preview includes core accessible navigation and audit disclaimer',()=>{
 const html=file('admin.html');
 for(const part of ['name="viewport"','id="role"','data-page="calendar"','data-page="booking"','data-page="finance"','data-page="approvals"','data-page="audit"','id="global-search"','id="booking-form"','immutable server audit trail','assets/admin.mjs'])assert.ok(html.includes(part),part);
});
test('staff prototype explicitly treats role switch as demo and avoids network writes',()=>{
 const js=file('assets/admin.mjs');assert.ok(js.includes("roles="));assert.ok(js.includes("permissions="));assert.ok(js.includes("calculateQuote("));assert.ok(!/fetch\s*\(|XMLHttpRequest|localStorage/.test(js));
});
test('frontend has mobile breakpoints and clear demo disclaimers',()=>{
 const css=file('assets/styles.css');assert.ok(css.includes('@media(max-width:770px)'));assert.ok(css.includes('.app-bottom-nav'));assert.ok(file('index.html').includes('does not submit customer information'));
});
