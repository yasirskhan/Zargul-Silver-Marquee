import {expect} from '@playwright/test';
import {createHmac} from 'node:crypto';
function otp(secret){
 const abc='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits=0,num=0,b=[];
 for(const c of secret){num=(num<<5)|abc.indexOf(c);bits+=5;
 if(bits>=8){bits-=8;b.push((num>>>bits)&255);}}
 const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const hash=createHmac('sha1',Buffer.from(b)).update(counter).digest();
 return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');
}
function isAuthenticated(url){
 const p=new URL(url).pathname;
 return p.startsWith('/admin')&& !['/admin/login','/admin/multi-factor-authentication','/admin/password-reset']
 .some(x=>p.startsWith(x));
}
// CI intentionally preserves the application's real login/MFA rate limiter.
// A sequential authenticated suite can exhaust the IP allowance; wait for
// the actual server retry notice rather than weakening or bypassing security.
async function respectLoginThrottle(page) {
 const notification=page.getByText(/Too many login attempts/i).first();
 const message=String(await notification.textContent());
 const match=message.match(/(\d+)\s+seconds?/i);
 const seconds=match?Number(match[1]):65;
 if(!Number.isFinite(seconds)||seconds<1||seconds>120)
   throw new Error('Unrecognized login throttle retry window');
 console.info('Preserving real login throttling: waiting '+seconds+'s before a fresh challenge');
 await page.waitForTimeout((seconds+2)*1000);
}
async function loginOutcome(page,challenge,{seconds=15}={}) {
 const notice=page.getByText(/Too many login attempts/i).first();
 for(let i=0;i<seconds*4;i++){
   if(isAuthenticated(page.url()))return 'authenticated';
   if(await notice.isVisible())return 'throttled';
   if(challenge && await challenge.isVisible())return 'challenge';
   await page.waitForTimeout(250);
 }
 return 'unknown';
}
export async function inventoryLogin(browser,email,password,secret,viewport){
 const ctx=await browser.newContext({viewport});
 const page=await ctx.newPage();
 await page.goto('/admin/login');
 await page.waitForFunction(()=>Boolean(window.Livewire?.all?.().some(c=>c.el?.querySelector?.('input[type="email"]'))),null,{timeout:30000});
 const field=page.locator('input[autocomplete="one-time-code"]:visible,input[id*="code"]:visible,input[name*="code"]:visible,input[inputmode="numeric"]:visible').first();
 let enteredChallenge=false;
 for(let attempt=0;attempt<4;attempt++){
   await page.locator('input[type="email"]').fill(email);
   await page.getByLabel(/Password/).fill(password);
   await page.getByRole('button',{name:/sign in|log in/i}).first().click();
   const result=await loginOutcome(page,field);
   if(result==='challenge'||result==='authenticated'){enteredChallenge=true;break;}
   if(result==='throttled'){await respectLoginThrottle(page);continue;}
   throw new Error('Real password login did not reach a challenge or authenticated panel');
 }
 if(!enteredChallenge)throw new Error('Real password authentication was repeatedly throttled');
 const verify=page.getByRole('button',{name:/^confirm sign in$/i});
 for(let attempt=0;attempt<4&&!isAuthenticated(page.url());attempt++){
   await expect(field).toBeVisible({timeout:20000});
   const remaining=30000-(Date.now()%30000);
   if(remaining<10000)await page.waitForTimeout(remaining+350);
   await field.fill(otp(secret));
   try{
     if(!isAuthenticated(page.url())){
       await expect(verify).toBeEnabled({timeout:5000});
       if(!isAuthenticated(page.url()))await verify.click();
     }
   }catch(error){
     if(!isAuthenticated(page.url()))throw error;
   }
   const result=await loginOutcome(page,null,{seconds:8});
   if(result==='authenticated')break;
   if(result==='throttled'){await respectLoginThrottle(page);continue;}
   // An invalid proof is a real failure. Never disable MFA or silently
   // retry arbitrary codes, since that would hide a security regression.
   throw new Error('MFA challenge was not accepted. Current path '+new URL(page.url()).pathname);
 }
 if(!isAuthenticated(page.url()))throw new Error('Actual two-factor authentication was not completed');
 await page.goto('/admin/hall-inventory');
 await expect(page.locator('[aria-label="Inventory workflow navigator"]')).toBeVisible({timeout:30000});
 await page.waitForFunction(()=>Boolean(window.Livewire?.all?.().length),null,{timeout:30000});
 const dialog=page.getByRole('dialog',{name:'Pending owner approval requests'});
 if(await dialog.isVisible())await dialog.getByRole('button',{name:'Dismiss for this session'}).click();
 return {ctx,page};
}
export async function inventoryFocus(page,name){
 await page.locator('[aria-label="Inventory tasks"]').getByRole('button',{name,exact:true}).click();
}
export async function inventoryUploadEvidence(page, input, file) {
  await input.evaluate(element => {
    window.phase12UploadPromise = new Promise((resolve, reject) => {
      const deadline = setTimeout(() => reject(new Error('Livewire evidence upload timed out')), 30000);
      element.addEventListener('livewire-upload-finish', () => { clearTimeout(deadline); resolve(true); }, { once: true });
      element.addEventListener('livewire-upload-error', () => { clearTimeout(deadline); reject(new Error('Livewire evidence upload rejected')); }, { once: true });
    });
  });
  await input.setInputFiles(file);
  await page.evaluate(() => window.phase12UploadPromise);
}
export const syntheticEvidence={
 name:'witness.pdf',mimeType:'application/pdf',
 buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF')
};
