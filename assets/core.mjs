// Demonstration-only pure booking/pricing rules. NOT a real reservation, accounting or authorization service.
export const HALLS=Object.freeze({
  grand:{name:'Grand Hall',capacity:500,includedTables:30,includedChairs:300,prices:{morning:150000,noon:180000,evening:240000}},
  garden:{name:'Garden Hall',capacity:350,includedTables:22,includedChairs:220,prices:{morning:112500,noon:135000,evening:180000}}
});
export const SLOTS=Object.freeze([
  {id:'morning',name:'Morning',start:'09:00',end:'12:00'},
  {id:'noon',name:'Noon',start:'13:00',end:'16:00'},
  {id:'evening',name:'Evening',start:'18:00',end:'22:00'}
]);
export const ADDONS=Object.freeze({
  music:{label:'Music and DJ',price:25000},
  lighting:{label:'Fancy lighting',price:18000},
  entry:{label:'Bride and groom special entry',price:12000},
  stage:{label:'Stage decoration',price:20000}
});
export const DEMO_BOOKINGS=Object.freeze([
  {id:'BK-0148',date:'2026-10-22',hall:'grand',slot:'evening',status:'confirmed',customer:'Ahsan and Maria'},
  {id:'BK-0149',date:'2026-10-22',hall:'garden',slot:'noon',status:'confirmed',customer:'Nadia and Ali'},
  {id:'BK-0151',date:'2026-10-24',hall:'grand',slot:'morning',status:'hold',customer:'Hamza and Fatima'}
]);
const count=v=>Math.max(0,Math.floor(Number(v)||0));
export function availability(date,hall,slot,bookings=DEMO_BOOKINGS){
 const matches=bookings.filter(b=>b.date===date&&b.hall===hall&&b.slot===slot&&['confirmed','hold'].includes(b.status));
 return matches.some(b=>b.status==='confirmed')?'booked':matches.length?'hold':'open';
}
export function calculateQuote({hall='grand',slot='evening',guests=0,extraTables=0,extraChairs=0,addons=[],catering=true,cateringRate=1500}={}){
 if(!HALLS[hall]||!SLOTS.some(s=>s.id===slot))throw Error('Invalid hall or slot');
 const venue=HALLS[hall],g=count(guests),t=count(extraTables),c=count(extraChairs);
 const lines=[{name:'Hall rental',amount:venue.prices[slot]}];
 if(catering)lines.push({name:'Catering',amount:g*count(cateringRate)});
 if(t)lines.push({name:'Additional tables',amount:t*1500});
 if(c)lines.push({name:'Additional chairs',amount:c*250});
 for(const key of new Set(addons))if(ADDONS[key])lines.push({name:ADDONS[key].label,amount:ADDONS[key].price});
 const seating=Math.min(venue.includedChairs+c,(venue.includedTables+t)*10);
 return {lines,total:lines.reduce((a,b)=>a+b.amount,0),seating,capacity:venue.capacity,seatsValid:g>0&&g<=seating&&g<=venue.capacity};
}
export function calculateOvertime(minutesLate,{graceMinutes=15,billingBlockMinutes=30,ratePerBlock=10000}={}){
 if(graceMinutes<0||billingBlockMinutes<=0||ratePerBlock<0)throw Error('Invalid overtime settings');
 const chargeableMinutes=Math.max(0,Math.ceil(Number(minutesLate)||0)-graceMinutes);
 const blocks=Math.ceil(chargeableMinutes/billingBlockMinutes);
 return {chargeableMinutes,blocks,amount:blocks*ratePerBlock};
}
export function canApprove({requesterId,approverId,approverRole,category,amount,delegation=null,at='2026-10-15T12:00:00Z'}){
 const value=Number(amount);
 if(!requesterId||!approverId||requesterId===approverId||!Number.isFinite(value)||value<0)return false;
 if(approverRole==='owner')return true;
 if(approverRole!=='admin'||!delegation||delegation.revoked||delegation.adminId!==approverId)return false;
 if(!delegation.categories||!Object.hasOwn(delegation.categories,category))return false;
 const t=Date.parse(at),from=Date.parse(delegation.startsAt),until=Date.parse(delegation.endsAt);
 return Number.isFinite(t)&&Number.isFinite(from)&&Number.isFinite(until)&&t>=from&&t<=until&&value<=Number(delegation.categories[category]);
}
