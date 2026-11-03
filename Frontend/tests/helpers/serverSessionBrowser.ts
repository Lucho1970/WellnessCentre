import {createHash,createSign,randomBytes} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {expect,type APIRequestContext,type APIResponse,type BrowserContext,type Page} from '@playwright/test';
type Grant={client:string;nonce:string;challenge:string;scope:string;profile:string;issuer:string};
const tenant='11111111-1111-4111-8111-111111111111';
const runtime=process.env.SERVER_SESSION_BROWSER_RUNTIME;
if(!runtime)throw new Error('Disposable runtime required');
const key=readFileSync(join(runtime,'private.pem'),'utf8');
const backend=`http://127.0.0.1:${process.env.SERVER_SESSION_BROWSER_API}`;
const b64=(value:string)=>Buffer.from(value).toString('base64url');
function sign(claims:Record<string,unknown>){const body=`${b64(JSON.stringify({alg:'RS256',typ:'JWT',kid:'session-test'}))}.${b64(JSON.stringify(claims))}`;return `${body}.${createSign('RSA-SHA256').update(body).sign(key).toString('base64url')}`;}
function headers(response:APIResponse){const output:Record<string,string>={};for(const h of response.headersArray()){const name=h.name.toLowerCase();if(['content-length','content-encoding','transfer-encoding','connection'].includes(name))continue;output[name]=output[name]?`${output[name]}${name==='set-cookie'?'\n':', '}${h.value}`:h.value;}return output;}
function attribute(value:string){return value.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
const fixtures=new WeakMap<BrowserContext,ServerSessionBrowser>();
export class ServerSessionBrowser{
  private client=randomBytes(8).toString('hex');
  private closing=false;
  static async teardown(context:BrowserContext){const fixture=fixtures.get(context);if(fixture)fixture.closing=true;await context.unrouteAll({behavior:'ignoreErrors'});}
  profile='workforce';hold=false;deny=false;access='';unexpected:string[]=[];responses:{path:string;status:number;code:string}[]=[];
  delayProtected:Promise<void>|null=null;
  private codes=new Map<string,Grant>();private refreshes=new Map<string,Grant>();
  constructor(private request:APIRequestContext){}
  async install(context:BrowserContext){
    if(this.profile==='microsoft')await this.microsoftStatus('active');
    fixtures.set(context,this);
    await context.route('**/*',async route=>{try{
      const incoming=route.request(),url=new URL(incoming.url());
      if(url.protocol!=='https:'){this.unexpected.push(url.origin);await route.abort();return;}
      if(['login.microsoftonline.com','teststaff.ciamlogin.com','testclients.ciamlogin.com'].includes(url.hostname)){
        const authority=`https://${url.hostname}/${tenant}`;
        const issuer=`https://${url.hostname==='login.microsoftonline.com'?url.hostname:`${tenant}.ciamlogin.com`}/${tenant}/v2.0`;
        if(url.pathname.endsWith('/authorize')){
          const redirect=new URL(url.searchParams.get('redirect_uri')??'https://invalid/');
          if(!['clinic-a.session.test','admin.session.test'].includes(redirect.hostname)||!['/tests/server-session/index.html','/staff/external','/client/auth/callback'].includes(redirect.pathname))throw new Error('Unapproved fixture redirect');
          const code=randomBytes(16).toString('hex');const client=url.searchParams.get('client_id')??'';
          if(!/^22222222-2222-4222-8222-22222222222[123]$/.test(client))throw new Error('Unapproved fixture client');
          this.codes.set(code,{client,nonce:url.searchParams.get('nonce')??'',challenge:url.searchParams.get('code_challenge')??'',scope:url.searchParams.get('scope')??'',profile:this.profile,issuer});
          redirect.searchParams.set(this.deny?'error':'code',this.deny?'access_denied':code);redirect.searchParams.set('state',url.searchParams.get('state')??'');
          await route.fulfill({contentType:'text/html',body:this.hold?`<a href="${attribute(redirect.href)}">Complete synthetic sign-in</a>`:`<meta http-equiv="refresh" content="0;url=${attribute(redirect.href)}">`});return;
        }
        if(url.pathname.endsWith('/token')){
          const form=new URLSearchParams(incoming.postData()??'');let grant:Grant|undefined;
          if(form.get('grant_type')==='authorization_code'){
            grant=this.codes.get(form.get('code')??'');this.codes.delete(form.get('code')??'');
            if(!grant||form.get('client_id')!==grant.client||createHash('sha256').update(form.get('code_verifier')??'').digest('base64url')!==grant.challenge)throw new Error('Synthetic PKCE failed');
          }else grant=this.refreshes.get(form.get('refresh_token')??'');
          if(!grant){await route.fulfill({status:400,json:{error:'invalid_grant'}});return;}
          const now=Math.floor(Date.now()/1000),n=grant.client.slice(-1);const oid=`aaaaaaaa-aaaa-4aaa-8aaa-${createHash('sha256').update(grant.profile).digest('hex').slice(0,12)}`;
          const common={iss:grant.issuer,tid:tenant,oid,ver:'2.0',iat:now-5,nbf:now-5,exp:now+86400};
          this.access=sign({...common,aud:`33333333-3333-4333-8333-33333333333${n}`,azp:grant.client,sub:`api-${grant.profile}`,scp:n==='2'?'access_as_staff':n==='3'?'access_as_client':'access_as_user',roles:['Wellness.SuperAdmin','Wellness.Practitioner']});
          const id=sign({...common,aud:grant.client,sub:`spa-${grant.profile}`,nonce:grant.nonce,auth_time:now,name:`Synthetic ${grant.profile}`,preferred_username:`${grant.profile}@example.test`});
          const refresh=`synthetic-refresh-${randomBytes(16).toString('hex')}`;this.refreshes.set(refresh,grant);
          await route.fulfill({json:{token_type:'Bearer',scope:grant.scope,expires_in:86400,access_token:this.access,id_token:id,refresh_token:refresh,client_info:b64(JSON.stringify({uid:oid,utid:tenant}))}});return;
        }
        if(url.pathname.includes('.well-known')){await route.fulfill({json:{issuer,authorization_endpoint:`${authority}/oauth2/v2.0/authorize`,token_endpoint:`${authority}/oauth2/v2.0/token`,end_session_endpoint:`${authority}/oauth2/v2.0/logout`,jwks_uri:`${authority}/discovery/v2.0/keys`,response_types_supported:['code'],subject_types_supported:['pairwise'],id_token_signing_alg_values_supported:['RS256']}});return;}
        this.unexpected.push(url.origin+url.pathname);await route.abort();return;
      }
      if(!['clinic-a.session.test','admin.session.test'].includes(url.hostname)){this.unexpected.push(url.origin);await route.abort();return;}
      if(url.pathname.startsWith('/api/v1/')){
        const response=await this.request.fetch(`${backend}${url.pathname}${url.search}`,{method:incoming.method(),headers:{...await incoming.allHeaders(),host:url.host,'X-Test-Client':this.client},data:incoming.postDataBuffer()??undefined,maxRedirects:0}).catch(()=>{throw new Error("Disposable API transport failed");});
        const json:unknown=await response.json();let code='';if(json&&typeof json==='object'&&'error' in json&&json.error&&typeof json.error==='object'&&'code' in json.error&&typeof json.error.code==='string')code=json.error.code;
        this.responses.push({path:url.pathname,status:response.status(),code});if(url.pathname==='/api/v1/auth/me'&&this.delayProtected)await this.delayProtected;
        if(route.request().isNavigationRequest()&&route.request().frame().isDetached())return;
        await route.fulfill({status:response.status(),headers:headers(response),body:await response.body()});return;
      }
      const path=url.pathname==='/'?'/tests/server-session/index.html':url.pathname;
      const response=await this.request.get(`http://127.0.0.1:5198${path}${url.search}`);await route.fulfill({status:response.status(),headers:headers(response),body:await response.body()});
    }catch(error){if(this.closing||route.request().failure())return;throw error;}
    });
  }
  async visit(page:Page,realm='workforce',lang='en'){await page.goto(`https://${realm==='central'?'admin':'clinic-a'}.session.test/?realm=${realm}&lang=${lang}`);await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeEnabled();}
  async login(page:Page){await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByRole('region',{name:'Private workspace'})).toBeVisible().catch(()=>{throw new Error(`Synthetic login denied: ${JSON.stringify(this.responses)}`);});}
  async microsoftStatus(status:'active'|'inactive'){
    const host='clinic-a.session.test';const common={iss:`https://login.microsoftonline.com/${tenant}/v2.0`,tid:tenant,ver:'2.0',oid:`aaaaaaaa-aaaa-4aaa-8aaa-${createHash('sha256').update('workforce').digest('hex').slice(0,12)}`,iat:Math.floor(Date.now()/1000)-5,nbf:Math.floor(Date.now()/1000)-5,exp:Math.floor(Date.now()/1000)+3600};
    const client='22222222-2222-4222-8222-222222222221';const bearer=sign({...common,aud:'33333333-3333-4333-8333-333333333331',azp:client,sub:'api-workforce',scp:'access_as_user',roles:['Wellness.SuperAdmin']});
    const h={host,Origin:`https://${host}`,'X-Test-Client':this.client};const challenge=await this.request.post(`${backend}/api/v1/staff/session/challenge`,{headers:h,data:{}});expect(challenge.status()).toBe(200);
    const nonce=(await challenge.json()).data.nonce;const cookieOf=(r:APIResponse)=>r.headersArray().filter(h=>h.name.toLowerCase()==='set-cookie'&&!h.value.includes('Max-Age=0')).map(h=>h.value.split(';')[0]).join('; ');
    const proof=sign({...common,aud:client,sub:'spa-workforce',nonce,auth_time:Math.floor(Date.now()/1000)});
    const started=await this.request.post(`${backend}/api/v1/staff/session/start`,{headers:{...h,Authorization:`Bearer ${bearer}`,Cookie:cookieOf(challenge)},data:{id_token:proof}});expect(started.status()).toBe(200);
    const response=await this.request.patch(`${backend}/api/v1/admin/staff/2`,{headers:{...h,Authorization:`Bearer ${bearer}`,Cookie:cookieOf(started),'X-Session-CSRF':(await started.json()).data.csrf},data:{display_name:'Synthetic Microsoft',email:'microsoft@example.test',status,roles:['practitioner'],permissions:[]}});expect(response.status()).toBe(200);
  }
}
