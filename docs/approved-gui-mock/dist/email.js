/* Email workflow preview: no actual mail delivery or real credentials. */
function mailSettings(){
  if(!data.mail){data.mail={ready:true,host:'smtp.example.test',port:587,encryption:'STARTTLS',username:'booking@example.test',sender:'Zargul Silver Marquee',from:'booking@example.test',reply:'info@example.test',minutes:10,resend:120,attempts:5,hold:10,outbox:[]};save()}
  if(data.mail.resend<120){data.mail.resend=120;save()}
  return data.mail
}
const normalizedEmail=v=>String(v||'').trim().toLowerCase();
const validEmail=v=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail(v));
let emailChallenge=null,emailGrant=null,emailSends=[],emailDraft={public:'',venue:''},awaitingEmail=false,emailTimer=null;
function resendRemaining(now=Date.now()){return Math.max(0,Math.ceil(((emailSends.at(-1)??(now-mailSettings().resend*1000))+mailSettings().resend*1000-now)/1000))}
function resendLabel(seconds){return Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0')}
function sendBookingCode(address,now=Date.now()){
  let a=normalizedEmail(address),s=mailSettings();
  if(!validEmail(a))return 'Enter a valid sample email address.';
  if(!s.ready)return 'Email verification is temporarily unavailable. Please contact the venue; no slot has been held.';
  emailSends=emailSends.filter(t=>t>now-15*60000);
  if(emailSends.length>=3)return 'Too many code requests. Try again in 15 minutes.';
  if(emailSends.length&&now-emailSends.at(-1)<s.resend*1000)return 'Please wait '+s.resend+' seconds before requesting another code.';
  emailDraft.public=a;emailGrant=null;emailChallenge={email:a,until:now+s.minutes*60000,attempts:0};emailSends.push(now);return '';
}
function verifyBookingCode(address,code,now=Date.now()){
  let a=normalizedEmail(address),c=emailChallenge,s=mailSettings();
  if(!s.ready||!c||c.email!==a||c.until<=now||c.attempts>=s.attempts)return false;
  c.attempts++;if(String(code)!=='123456')return false;
  emailGrant={email:a,until:now+30*60000};emailChallenge=null;return true
}
function publicEmailVerified(address,now=Date.now()){return !!emailGrant&&emailGrant.email===normalizedEmail(address)&&emailGrant.until>now}
function checkBookingEmail(fd){
  if(bookingStep===0){let key=mode==='public'?'public':'venue',a=normalizedEmail(fd['client-email']);emailDraft[key]=a;
    if(mode==='public'){
      if(!validEmail(a)){toast('Enter your email address.');return false}
      if(!String(fd.firstName||'').trim()||!String(fd.lastName||'').trim()||!String(fd.phone||'').trim()){toast('Enter your first name, last name and phone number.');return false}
      for(let k of ['event','date','guest','firstName','lastName','phone'])booking[k]=fd[k]??booking[k];
      booking.name=fd.firstName.trim()+' '+fd.lastName.trim();booking.email=a;
      if(!eligibleHalls().length){toast('No hall can accommodate your guest count.');return false}
      if(!publicEmailVerified(a)){
        if(!emailChallenge||emailChallenge.email!==a||emailChallenge.until<=Date.now()){let err=sendBookingCode(a);if(err){toast(err);return false}}
        awaitingEmail=true;render();return false
      }
      fd.name=booking.name;awaitingEmail=false;
    }
    if(a&&!validEmail(a)){toast('Enter a valid email address or leave it empty for staff bookings.');return false}
    booking.email=a;delete fd['client-email'];delete fd['client-code'];
  }
  if(mode==='public'&&!publicEmailVerified(booking.email)){bookingStep=0;render();toast('Email verification is required before booking.');return false}
  return true
}
function emailFields(publicMode){
  let a=emailDraft[publicMode?'public':'venue'];
  return '<section class="config-card" style="margin-top:16px"><h3>'+(publicMode?'Your email':'Customer email (optional)')+'</h3><p class="muted config-help">'+(publicMode?'We will email a six-digit verification code when you select Next. No time slot will be held yet.':'If provided, send the customer their invoice whether it is paid, partly paid or unpaid. Leave blank for walk-in customers.')+'</p>'+payInput(publicMode?'Email address (required)':'Email address (optional)','client-email',a,'email',publicMode,'placeholder="customer@example.test"')+'<p class="muted config-help">Demo only. Use example.test addresses, not real client information.</p></section>'
}
function verificationForm(){
  let s=mailSettings(),remaining=resendRemaining(),a=emailDraft.public;
  return '<form id="email-code-form"><div class="config-card"><h3>Verify your email address</h3><p class="muted">We sent a six-digit code to <b>'+esc(a)+'</b>. Enter it below to continue. No slot has been held.</p><div class="note">Demo email: use <b>123456</b>. The code expires in '+s.minutes+' minutes.</div>'+payInput('Verification code','client-code','','text',true,'inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"')+'<div class="formfoot">'+cfgButton('Change details','mail-edit-details','outline')+'<button class="btn gold" type="submit">Verify & continue →</button></div><div class="actions" style="margin-top:16px"><button type="button" id="resend-booking-code" class="btn outline" data-action="mail-resend-code" '+(remaining?'disabled':'')+'>Resend code</button><span id="resend-booking-countdown" aria-live="polite">'+(remaining?'Resend available in '+resendLabel(remaining):'You can request a new code now.')+'</span></div></div></form>'
}
function scheduleResendCountdown(){
  clearTimeout(emailTimer);
  emailTimer=setTimeout(function tick(){
    let button=$('#resend-booking-code'),label=$('#resend-booking-countdown');
    if(!button||!label||!awaitingEmail||mode!=='public')return;
    let n=resendRemaining();button.disabled=n>0;label.textContent=n?'Resend available in '+resendLabel(n):'You can request a new code now.';
    if(n){emailTimer=setTimeout(tick,250);emailTimer?.unref?.()}
  },250);emailTimer?.unref?.()
}
function advanceEmailVerification(code){
  if(!awaitingEmail||!verifyBookingCode(emailDraft.public,code))return false;
  let available=eligibleHalls();if(!available.length){awaitingEmail=false;render();return false}
  booking.email=emailDraft.public;
  if(!available.some(h=>h.name===booking.hall)){booking.hall=available[0].name;booking.program=available[0].programs[0].name}
  awaitingEmail=false;bookingStep=1;render();window.scrollTo(0,0);return true
}
const beforeMailBooking=bookingFlow;
bookingFlow=function(publicMode){
  if(publicMode&&bookingStep>0&&bookingStep<4&&!publicEmailVerified(booking.email))bookingStep=0;
  let html=beforeMailBooking(publicMode);
  if(bookingStep===0){
    if(publicMode&&awaitingEmail){html=html.replace(/<form id="booking-form">[\s\S]*?<\/form>/,verificationForm());scheduleResendCountdown()}
    else{html=html.replace('<div class="formfoot">',emailFields(publicMode)+'<div class="formfoot">');if(publicMode)html=html.replace('Continue →','Next →')}
  }
  return html
};
function queueInvoiceEmail(ref){
  let d=opsState().deals[ref],s=mailSettings();
  if(!d?.email)return;
  let paid=d.paid,total=d.total,due=Math.max(0,total-paid),status=due===0?'Paid':paid>0?'Partially paid':'Unpaid';
  let snapshot=[ref,d.email,total,paid,due,status].join('|');
  if(s.outbox.some(x=>x.snapshot===snapshot))return;
  s.outbox.push({snapshot,to:d.email,subject:'Invoice '+ref+' · '+status,total,paid,due,status,at:new Date().toISOString(),delivery:s.ready?'Simulated sent':'Pending delivery'});save()
}
const beforeMailPay=completeMockPayment;
completeMockPayment=function(fd,h,publicMode){
  if(publicMode&&!publicEmailVerified(booking.email)){toast('Email verification required. No booking created.');return false}
  let ok=beforeMailPay(fd,h,publicMode);
  if(ok){let d=opsState().deals[booking.reference];if(d){d.email=normalizedEmail(booking.email);queueInvoiceEmail(booking.reference);save()}if(publicMode){emailGrant=null;emailChallenge=null;emailDraft.public='';}else emailDraft.venue='';booking.email=''}
  return ok
};
const beforeMailBalance=recordBalance;
recordBalance=function(ref,fd){let ok=beforeMailBalance(ref,fd);if(ok)queueInvoiceEmail(ref);return ok};
function mailPage(){
  let s=mailSettings();
  if(!isOwner())return pagehead('Access restricted','Only the owner can configure email.');
  return pagehead('Email & booking verification','Configure outgoing email, public booking verification and invoice delivery.',cfgButton('Preview public booking','route:public/booking','outline','globe'))+
  metrics([['Mail delivery',s.ready?'Demo ready':'Unavailable','message'],['Public email verification','Required','shield'],['Code expiry',s.minutes+' minutes','clock'],['Queued invoices',String(s.outbox.length),'book']])+
  '<form id="mail-settings-form">'+card('1. Outgoing mail (SMTP)','<div class="fields">'+payInput('SMTP hostname','host',s.host)+payInput('SMTP port','port',s.port,'number',true,'min="1" max="65535"')+'<label class="field">Encryption<select name="encryption">'+['STARTTLS','TLS'].map(v=>selectSecurityOption(v,v,s.encryption)).join('')+'</select></label>'+payInput('SMTP username (sample)','username',s.username)+'<label class="field">SMTP password / app password<input type="password" value="demo-secret" disabled><small>Real application: enter securely on server; never expose credentials.</small></label>'+payInput('Sender display name','sender',s.sender)+payInput('From email','from',s.from,'email')+payInput('Reply-to email','reply',s.reply,'email')+'</div><div class="actions" style="margin-top:12px">'+cfgButton('Test email connection (demo)','mail-test','outline','check')+cfgButton(s.ready?'Simulate unavailable mail':'Restore demo mail','mail-toggle','outline')+'</div><div class="note">The mock does not send actual email. Configure a verified sender and store real SMTP secrets securely in VenueDesk.</div>')+
  card('2. Prevent booking abuse','<div class="note">'+badge('Required')+' Public booking email verification cannot be skipped. Staff-created reservations do not require an email.</div><div class="fields">'+payInput('Code expires after (minutes)','minutes',s.minutes,'number',true,'min="2" max="15"')+payInput('Resend delay (seconds, minimum 120)','resend',s.resend,'number',true,'min="120" max="300"')+payInput('Maximum code attempts','attempts',s.attempts,'number',true,'min="3" max="5"')+payInput('Temporary hold timeout (minutes)','hold',s.hold,'number',true,'min="5" max="30"')+'</div><p class="muted config-help">Production: enforce per-email and per-IP rate limits, one active hold per verified customer, atomic slot checks, single-use codes and automatic release of expired holds. Never reserve a slot for an unverified email.</p>')+
  card('3. Invoice email behavior','<p>Public bookings: use the verified email. Staff bookings: email is optional; if supplied, send the invoice regardless of balance. Send an updated invoice when recorded payments change the balance. No email address means no delivery.</p><p class="muted">Delivery failure does not roll back a valid staff booking. Queue/retry through the mail worker.</p><button class="btn gold" type="submit">Save email settings</button>')+'</form>'+
  card('Demo invoice outbox','<div class="table-wrap"><table><thead><tr><th>Recipient</th><th>Invoice</th><th>Paid</th><th>Due</th><th>Delivery</th></tr></thead><tbody>'+s.outbox.slice().reverse().map(x=>'<tr><td>'+esc(x.to)+'</td><td>'+esc(x.subject)+'</td><td>'+money(x.paid)+'</td><td>'+money(x.due)+'</td><td>'+badge(x.delivery)+'</td></tr>').join('')+'</tbody></table></div><p class="muted">Sample email records only; no actual messages sent.</p>')
}
function saveMailSettings(fd){
  if(!isOwner()||!fd.host.trim()||!fd.sender.trim()||!validEmail(fd.from)||!validEmail(fd.reply)||!['STARTTLS','TLS'].includes(fd.encryption))return false;
  for(let [k,min,max] of [['port',1,65535],['minutes',2,15],['resend',120,300],['attempts',3,5],['hold',5,30]])if(!Number.isInteger(+fd[k])||+fd[k]<min||+fd[k]>max)return false;
  let s=mailSettings();for(let k of ['host','encryption','username','sender','from','reply'])s[k]=fd[k];for(let k of ['port','minutes','resend','attempts','hold'])s[k]=+fd[k];save();return true
}
venueDefs.emailSettings=def('Email & booking verification','Configure SMTP and customer email.','message',[],[],[],'');settingsGroup.unshift(['emailSettings','Email & verification','message']);permsByPage.emailSettings='owner';
const beforeMailGeneric=generic;
generic=function(c,key){return key==='venue-emailSettings'?mailPage():beforeMailGeneric(c,key)};
const beforeMailAction=action;
action=function(v){
  if(v==='mail-edit-details'||v==='mail-resend-code'){
    if(mode!=='public'||bookingStep!==0)return;
    if(v==='mail-edit-details'){awaitingEmail=false;render();return}
    if(!awaitingEmail)return;let err=sendBookingCode(emailDraft.public);if(err){toast(err);return}render();toast('New demo code sent.');return
  }
  if(v==='mail-test'||v==='mail-toggle'){if(!isOwner())return;let s=mailSettings();if(v==='mail-toggle'){s.ready=!s.ready;if(s.ready)s.outbox.filter(x=>x.delivery==='Pending delivery').forEach(x=>x.delivery='Simulated sent');save();render();return}toast(s.ready?'Demo SMTP connection successful.':'Demo email service unavailable.');return}
  let z=v.match(/^pay-review:(\d+):verified$/),ref=z?paymentClaims()[+z[1]]?.booking:null;
  beforeMailAction(v);if(ref)queueInvoiceEmail(ref)
};
document.addEventListener('submit',e=>{if(!['email-code-form','mail-settings-form'].includes(e.target.id))return;e.preventDefault();e.stopImmediatePropagation();let fd=Object.fromEntries(new FormData(e.target));if(e.target.id==='email-code-form'){if(!advanceEmailVerification(fd['client-code']))toast('Invalid or expired verification code.');return}if(saveMailSettings(fd)){render();toast('Email settings saved.')}else toast('Enter valid mail settings.')},true);
mailSettings();render();
