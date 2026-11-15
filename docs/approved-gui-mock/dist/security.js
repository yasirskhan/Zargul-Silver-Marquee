/* Venue authentication design preview. No real passwords, OTPs or device tokens are stored. */
function securitySettings(){
  if(!data.security){data.security={required:true,days:30,users:{},trusted:{},audit:[]};save()}
  return data.security
}
function requiresTwoStep(id){let s=securitySettings();return s.users[id]==='on'||(s.users[id]!=='off'&&s.required)}
function trustedDevice(id,now=Date.now()){let s=securitySettings();return s.days>0&&Number(s.trusted[id]||0)>now}
function loginNeedsCode(id,now=Date.now()){return requiresTwoStep(id)&&!trustedDevice(id,now)}
function updateSecurity(fd){
  if(!isOwner()||!['0','7','14','30','60','90'].includes(String(fd.days))||!['on','off'].includes(fd.global))return false;
  let s=securitySettings(),users=opsState().users;
  if(users.some(u=>!['inherit','on','off'].includes(fd['mfa-'+u.id])))return false;
  let next=Object.fromEntries(users.map(u=>[u.id,fd['mfa-'+u.id]])),days=Number(fd.days);
  if(s.required!==(fd.global==='on')||s.days!==days) s.trusted={};
  for(let u of users)if((s.users[u.id]||'inherit')!==next[u.id])delete s.trusted[u.id];
  s.required=fd.global==='on';s.days=days;s.users=next;
  s.audit.push({at:new Date().toISOString(),event:'Two-step policy updated by owner'});save();return true
}
function setAllSecurity(required){
  if(!isOwner())return false;
  let s=securitySettings();s.required=required;s.users={};s.trusted={};
  s.audit.push({at:new Date().toISOString(),event:required?'Two-step enabled for all users':'Two-step disabled for all users'});save();return true
}
function selectSecurityOption(v,label,current){return '<option value="'+v+'" '+(String(v)===String(current)?'selected':'')+'>'+esc(label)+'</option>'}
function securityPage(){
  let s=securitySettings(),users=opsState().users;
  if(!isOwner())return pagehead('Access restricted','Only the venue owner may change these settings.');
  return pagehead('Security & sign-in','Manage two-step authentication for your own account and every staff member.',cfgButton('Preview sign-in','security-signout','outline','key'))+
  metrics([['Default policy',s.required?'Enabled':'Disabled','shield'],['Users requiring a code',String(users.filter(u=>u.active&&requiresTwoStep(u.id)).length),'users'],['Remember device',s.days?s.days+' days':'Every sign-in','clock'],['Trusted in this demo',String(users.filter(u=>trustedDevice(u.id)).length),'check']])+
  '<form id="security-form">'+card('1. Global sign-in policy',
    '<div class="fields"><label class="field">Two-step authentication default<select name="global">'+selectSecurityOption('on','Enabled for all by default',s.required?'on':'off')+selectSecurityOption('off','Disabled for all by default',s.required?'on':'off')+'</select></label><label class="field">Remember trusted devices for<select name="days">'+[0,7,14,30,60,90].map(d=>selectSecurityOption(d,d===0?'Never (ask every login)':d+' days',s.days)).join('')+'</select></label></div><p class="muted config-help">Individual overrides take priority over the global default. Enable trusted devices to avoid repeated codes on the same browser.</p><div class="actions">'+cfgButton('Enable for everyone','security-all:on','outline','shield')+cfgButton('Disable for everyone','security-all:off','outline')+'</div>')+
  card('2. Individual user settings','<div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Override</th><th>Effective policy</th><th>Trusted device</th></tr></thead><tbody>'+users.map(u=>'<tr><td><b>'+esc(u.name)+'</b><small style="display:block">'+esc(u.email)+'</small></td><td>'+esc(u.role)+(u.id==='owner'?' (you)':'')+'</td><td><select name="mfa-'+esc(u.id)+'" aria-label="Two-step policy for '+esc(u.name)+'">'+selectSecurityOption('inherit','Follow global default',s.users[u.id]||'inherit')+selectSecurityOption('on','Always enabled',s.users[u.id]||'inherit')+selectSecurityOption('off','Disabled',s.users[u.id]||'inherit')+'</select></td><td>'+badge(requiresTwoStep(u.id)?'Enabled':'Disabled')+'</td><td>'+(trustedDevice(u.id)?'Remembered '+cfgButton('Revoke','security-revoke:'+esc(u.id),'outline sm'):'Not trusted')+'</td></tr>').join('')+'</tbody></table></div><div class="note">Recommended: keep two-step enabled for owners and privileged users and remember their private devices. Never remember a shared computer. Production changes to sensitive settings should require fresh verification and be audited.</div><button type="submit" class="btn gold">Save security settings</button>')+'</form>'+
  card('3. Trusted devices & activity','<p class="muted">A new browser, expired trust, password reset, or revoked device must verify again when two-step is enabled. Sensitive actions may require fresh verification.</p><div class="actions">'+cfgButton('Revoke all trusted devices','security-revoke-all','outline','shield')+'</div><div class="table-wrap"><table><thead><tr><th>When</th><th>Security event</th></tr></thead><tbody>'+s.audit.slice().reverse().map(a=>'<tr><td>'+esc(new Date(a.at).toLocaleString())+'</td><td>'+esc(a.event)+'</td></tr>').join('')+' </tbody></table></div><div class="note">Demo only. This page changes mock behavior, not real authentication or HAFZATECH Central security.</div>')
}
venueDefs.securitySettings=def('Security & sign-in','Configure two-step authentication.','shield',[],[],[],'');
settingsGroup.unshift(['securitySettings','Security & sign-in','shield']);permsByPage.securitySettings='owner';
const beforeSecurityGeneric=generic;
generic=function(c,key){return key==='venue-securitySettings'?securityPage():beforeSecurityGeneric(c,key)};
let mockLoginUser='owner',mockLoginChallenge=false;
const beforeSecurityLogin=login;
login=function(){
  if(mode!=='venue')return beforeSecurityLogin();
  let u=opsState().users.find(u=>u.id===mockLoginUser&&u.active)||opsState().users.find(u=>u.active),s=securitySettings();
  return '<div class="login-wrap"><section class="card login-card"><div class="brand"><i>✦</i>VenueDesk</div><h1 style="margin-top:20px;font-size:30px">Secure sign in</h1><p class="muted">Design demonstration. Do not enter real credentials.</p>'+
    (mockLoginChallenge?'<form id="mock-mfa-form"><p>Verify sign-in for '+esc(u.name)+'</p>'+payInput('One-time code (demo: 123456)','code','123456','text',true,'inputmode="numeric" pattern="[0-9]{6}"')+(s.days?payCheckbox('remember','Remember this private device for '+s.days+' days',true):'')+'<button class="btn primary" style="width:100%;margin-top:12px" type="submit">Verify and sign in</button>'+cfgButton('Back','security-back','outline')+'</form>':
    '<form id="mock-signin-form"><label class="field">Demo user<select name="demo-user">'+opsState().users.filter(u=>u.active).map(x=>selectSecurityOption(x.id,x.name+' · '+x.role,u.id)).join('')+'</select></label>'+payInput('Sample password','password','mock-password','password')+'<button class="btn primary" style="width:100%" type="submit">Sign in to demo</button><p class="muted config-help">If two-step is enabled, a code is requested only when this device is not trusted.</p></form>')+'</section></div>'
};
function finishDemoLogin(id){
  if(!opsState().users.some(u=>u.id===id&&u.active)||installState.status!=='installed'||!installState.healthy)return false;
  previewActor=id;mockLoginChallenge=false;installState.signedIn=true;persistInstall();installerGo('venue','dashboard');return true
}
function beginDemoLogin(id){
  if(!opsState().users.some(u=>u.id===id&&u.active)||installState.status!=='installed'||!installState.healthy)return false;
  mockLoginUser=id;mockLoginChallenge=loginNeedsCode(id);
  if(mockLoginChallenge){render();return 'challenge'}return finishDemoLogin(id)
}
function verifyDemoLogin(code,remember){
  if(!mockLoginChallenge||code!=='123456'||!opsState().users.some(u=>u.id===mockLoginUser&&u.active))return false;
  let s=securitySettings();if(remember&&s.days)s.trusted[mockLoginUser]=Date.now()+s.days*86400000;save();return finishDemoLogin(mockLoginUser)
}
const beforeSecurityAction=action;
action=function(v){
  if(v==='security-signout'){if(mode!=='venue')return;mockLoginUser=actor().id;mockLoginChallenge=false;installState.signedIn=false;persistInstall();installerGo('venue','login');return}
  if(v==='security-back'){mockLoginChallenge=false;render();return}
  if(v.startsWith('security-')){if(!isOwner())return;let s=securitySettings();
    if(v.startsWith('security-all:')){setAllSecurity(v.endsWith(':on'));render();toast('Global policy saved; individual overrides reset.');return}
    if(v==='security-revoke-all')s.trusted={};else if(v.startsWith('security-revoke:'))delete s.trusted[v.slice('security-revoke:'.length)];else return;
    s.audit.push({at:new Date().toISOString(),event:'Trusted device access revoked by owner'});save();render();toast('Trusted device access revoked.');return}
  beforeSecurityAction(v)
};
document.addEventListener('submit',e=>{
  if(!['security-form','mock-signin-form','mock-mfa-form'].includes(e.target.id))return;e.preventDefault();e.stopImmediatePropagation();
  let fd=Object.fromEntries(new FormData(e.target));
  if(e.target.id==='security-form'){if(updateSecurity(fd)){render();toast('Security settings saved.')}else toast('Unable to save security settings.')}
  else if(e.target.id==='mock-signin-form'){if(fd.password!=='mock-password'||!beginDemoLogin(fd['demo-user']))toast('Use the sample password and an active demo user.')}
  else if(!verifyDemoLogin(fd.code,!!fd.remember))toast('Use the sample code 123456.');
},true);
const beforeSecurityRender=render;
render=function(){beforeSecurityRender();if(mode==='venue'&&installState.signedIn){let target=$('.topright');if(target)target.insertAdjacentHTML('beforeend',cfgButton('Sign out','security-signout','outline sm','key'))}};
securitySettings();render();
