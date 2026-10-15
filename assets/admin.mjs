import {HALLS,SLOTS,availability,calculateQuote} from './core.mjs';
const $=s=>document.querySelector(s),all=s=>Array.from(document.querySelectorAll(s));
const roles={owner:{label:'Owner / Admin',metrics:[['Today\'s Events','4'],['Collections','Rs 485K'],['Pending Approvals','1'],['Cash Variance','Rs 0']]},manager:{label:'Hall Manager',metrics:[['Today\'s Events','3'],['New Enquiries','5'],['Awaiting Approval','1'],['Open Slots','4']]},reception:{label:'Reception',metrics:[['Today\'s Events','3'],['New Enquiries','5'],['Open Slots','4'],['Follow-ups','2']]},cashier:{label:'Cashier',metrics:[['Shift Collections','Rs 126K'],['Receipts','8'],['Outstanding','Rs 550K'],['Cash Variance','Rs 0']]}};
const permissions={owner:['dashboard','calendar','booking','finance','approvals','audit'],manager:['dashboard','calendar','booking','finance','approvals','audit'],reception:['dashboard','calendar','booking'],cashier:['dashboard','calendar','finance']};
const titles={dashboard:['Business Overview','Your venue at a glance'],calendar:['Hall Availability','Morning, noon and evening'],booking:['New Reservation','Build a quote in a few steps'],finance:['Finance & Invoices','Example payment and invoice records'],approvals:['Owner Approval Center','Requests and financial review'],audit:['Activity History','Audit timeline preview']};
const money=x=>'Rs '+Number(x).toLocaleString('en-PK');
let role='owner',page='dashboard',approval='pending';
function accessible(view){return permissions[role].includes(view)}
function show(view){
 if(!accessible(view))view='dashboard';page=view;
 all('[data-view]').forEach(el=>el.classList.toggle('hidden',el.dataset.view!==page));
 all('[data-page]').forEach(el=>{el.classList.toggle('active',el.dataset.page===page);el.classList.toggle('hidden',!accessible(el.dataset.page))});
 $('#page-title').textContent=titles[page][0];$('#page-subtitle').textContent=titles[page][1];
 if(page==='calendar')renderCalendar();
}
function renderMetrics(){
 $('#metrics').innerHTML=roles[role].metrics.map(([k,v])=>`<div class="metric"><small>${k}</small><strong>${v}</strong></div>`).join('');
}
function renderCalendar(){
 const h=$('#calendar-hall').value,date=$('#calendar-date').value;
 const html=SLOTS.map(s=>{const st=availability(date,h,s.id);return `<div class="slot"><b>${s.name}</b><small>${s.start}–${s.end}</small><span class="status ${st==='open'?'':st}">${st==='open'?'Available':st==='hold'?'Tentative':'Booked'}</span></div>`}).join('');
 $('#calendar-slots').innerHTML=html;
 const today=availability(date,h,'evening');
 $('#overview-slots').innerHTML=html;
 $('#overview-slots').setAttribute('aria-label',`Sample booking availability, evening status: ${today}`);
}
function refreshFinance(){
 const after=approval==='approved',value=after?750000:800000;
 $('#invoice-total').textContent=money(value);$('#invoice-balance').textContent=money(value-250000);
 $('#approved-discount-value').textContent=after?'− Rs 50,000':approval==='rejected'?'Rejected • Rs 0':'Pending • not applied';
 const st=$('#approval-state');st.textContent=approval==='pending'?'Pending':approval==='approved'?'Approved':'Rejected';st.className='status '+(approval==='pending'?'hold':approval==='rejected'?'booked':'');
 $('#approval-actions').classList.toggle('hidden',role!=='owner'||approval!=='pending');
 $('#approval-message').textContent=approval==='approved'?'Approved in this demonstration. Customer price now Rs 750,000.':approval==='rejected'?'Request rejected in this demonstration. Price remains Rs 800,000.':role==='manager'?'Awaiting owner decision. Manager cannot self-approve.':'Owner approval required.';
 const d=$('#audit-decision');d.textContent='';
 if(approval!=='pending'){const row=document.createElement('div');row.className='record';const desc=document.createElement('div');const strong=document.createElement('b');strong.textContent=approval==='approved'?'Discount approved':'Discount rejected';const sm=document.createElement('small');sm.textContent='Ahmed Khan • Owner • Demo decision only';desc.append(strong,sm);row.append(desc);d.append(row)}
}
const entries=[
 {title:'Booking BK-0148 • Ahsan & Maria',desc:'Grand Hall • Evening',view:'booking',roles:['owner','manager','reception','cashier']},
 {title:'Invoice INV-0148 • Ahsan & Maria',desc:'Rs 800,000 authorized • Rs 250,000 received',view:'finance',roles:['owner','manager','cashier']},
 {title:'Payment PAY-0401 • Ahsan & Maria',desc:'Rs 250,000 cash advance',view:'finance',roles:['owner','manager','cashier']},
 {title:'Approval APR-1042 • Sara Malik',desc:'Rs 50,000 • Owner review pending',view:'approvals',roles:['owner','manager']},
 {title:'Customer Ahsan & Maria',desc:'Booking BK-0148',view:'booking',roles:['owner','manager','reception']}
];
function search(term){
 const box=$('#search-results'),q=String(term||'').trim().toLowerCase();box.replaceChildren();box.classList.toggle('hidden',!q);if(!q)return;
 const found=entries.filter(e=>e.roles.includes(role)&&(e.title+' '+e.desc).toLowerCase().includes(q));
 const h=document.createElement('h3');h.textContent=`Search matches: ${found.length}`;box.append(h);
 if(!found.length){const p=document.createElement('p');p.className='empty';p.textContent='No accessible matching demo records.';box.append(p);return}
 for(const e of found){const b=document.createElement('button');b.className='record button outline';b.style.width='100%';b.style.justifyContent='space-between';b.textContent=`${e.title} — ${e.desc}`;b.addEventListener('click',()=>{show(e.view);$('#global-search').value='';search('')});box.append(b)}
}
all('[data-page]').forEach(el=>el.addEventListener('click',()=>show(el.dataset.page)));
all('[data-go]').forEach(el=>el.addEventListener('click',()=>show(el.dataset.go)));
$('#role').addEventListener('change',e=>{role=e.target.value;renderMetrics();show(page);refreshFinance();search($('#global-search').value)});
$('#calendar-hall').addEventListener('change',renderCalendar);
$('#calendar-date').addEventListener('change',renderCalendar);
$('#global-search').addEventListener('input',e=>search(e.target.value));
$('#booking-form').addEventListener('submit',e=>{
 e.preventDefault();
 if(!accessible('booking'))return;
 const f=new FormData(e.currentTarget),addons=['music','lighting','entry'].filter(k=>f.has(k));
 const q=calculateQuote({hall:f.get('hall'),slot:f.get('slot'),guests:f.get('guests'),extraTables:f.get('tables'),extraChairs:f.get('chairs'),addons,catering:f.has('catering')});
 const out=$('#booking-quote');out.classList.remove('hidden');out.replaceChildren();
 const heading=document.createElement('strong');heading.textContent=money(q.total);out.append(heading);
 const p=document.createElement('p');p.textContent=q.seatsValid?`Seating valid for ${f.get('guests')} guests under this illustrative arrangement.`:'Seating or hall capacity needs review before confirmation.';out.append(p);
 const list=document.createElement('ul');for(const line of q.lines){const li=document.createElement('li');li.textContent=`${line.name}: ${money(line.amount)}`;list.append(li)}out.append(list);
 const disclaimer=document.createElement('small');disclaimer.textContent='Demo only: no quotation, reservation or invoice has been saved.';out.append(disclaimer);
});
$('#approve-approval').addEventListener('click',()=>{if(role!=='owner'||approval!=='pending')return;approval='approved';refreshFinance()});
$('#reject-approval').addEventListener('click',()=>{if(role!=='owner'||approval!=='pending')return;approval='rejected';refreshFinance()});
renderMetrics();renderCalendar();refreshFinance();show(page);
