import {availability,HALLS} from './core.mjs';
const menu=document.querySelector('#menu-toggle'),nav=document.querySelector('#site-nav');
menu?.addEventListener('click',()=>{const open=nav.classList.toggle('open');menu.setAttribute('aria-expanded',String(open))});
nav?.addEventListener('click',e=>{if(e.target.closest('a')){nav.classList.remove('open');menu?.setAttribute('aria-expanded','false')}});
const form=document.querySelector('#enquiry-form');
form?.addEventListener('submit',e=>{
 e.preventDefault();
 const f=new FormData(form),name=String(f.get('name')||''),date=String(f.get('date')||''),hall=String(f.get('hall')||''),slot=String(f.get('slot')||''),guests=Number(f.get('guests'));
 const result=document.querySelector('#enquiry-result');
 if(!HALLS[hall]||!Number.isSafeInteger(guests)||guests<1||!date){result.textContent='Please enter valid event details.';return}
 const s=availability(date,hall,slot);
 result.textContent=`${name}: ${HALLS[hall].name}, ${date}, ${slot} — sample status: ${s}. ${guests>HALLS[hall].capacity?'Guest count exceeds illustrative hall capacity.':'A staff member must verify availability and final pricing.'} No enquiry has been sent.`;
});
