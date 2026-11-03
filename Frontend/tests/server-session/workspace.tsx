import {useState,useSyncExternalStore} from 'react';
import {createRoot} from 'react-dom/client';
import {PublicClientApplication} from '@azure/msal-browser';
import {ThemeProvider,createTheme} from '@mui/material';
import {configureServerSession,prepareServerSession,sessionSnapshot,subscribeSession,signInServerSession,readServerSessionToken,sessionFetch,signOutServerSession,type SessionRealm} from '../../src/auth/serverSession';
import {ServerSessionNotice} from '../../src/auth/ServerSessionNotice';
import {eraseLegacyIdentityCache} from '../../src/auth/serverSessionMode';
import {privateSessionStorage} from '../../src/auth/privateSessionStorage';
import i18n from '../../src/i18n';
const tenant='11111111-1111-4111-8111-111111111111';
export async function bootstrap(){
  const value=new URL(location.href).searchParams.get('realm')??'workforce';
  if(!['workforce','practitioner','central','client'].includes(value))throw new Error('Invalid fixture realm');
  const realm=value as SessionRealm;const n=realm==='practitioner'?2:realm==='client'?3:1;
  const spa=`22222222-2222-4222-8222-22222222222${n}`,api=`33333333-3333-4333-8333-33333333333${n}`;
  const external=realm==='practitioner'||realm==='client';const host=external?`${realm==='client'?'testclients':'teststaff'}.ciamlogin.com`:'login.microsoftonline.com';
  const scopes=[`api://${api}/${realm==='practitioner'?'access_as_staff':realm==='client'?'access_as_client':'access_as_user'}`];
  eraseLegacyIdentityCache([spa]);
  const instance=new PublicClientApplication({auth:{clientId:spa,authority:`https://${host}/${tenant}`,knownAuthorities:[host],redirectUri:`${location.origin}/tests/server-session/index.html`},cache:{cacheLocation:'memoryStorage'}});
  configureServerSession(realm,{instance,scopes,redirectUri:`${location.origin}/tests/server-session/index.html`});await instance.initialize();
  if(new URL(location.href).searchParams.get('lang')==='fr')await i18n.changeLanguage('fr');
  try{await prepareServerSession();}catch{/* Production notice provides explicit retry. */}
  function Workspace(){
    const session=useSyncExternalStore(subscribeSession,sessionSnapshot);const [data,setData]=useState(''),[error,setError]=useState('');
    async function read(){try{const token=await readServerSessionToken();const path=realm==='central'?'application/me':realm==='client'?'customer/auth/me':'auth/me';const response=await sessionFetch(`/api/v1/${path}`,{headers:{Authorization:`Bearer ${token}`}});setData(JSON.stringify(await response.json()));}catch{setData('');setError('Session denied');}}
    return <ThemeProvider theme={createTheme()}><main><h1>Synthetic session integration</h1><ServerSessionNotice/>
      <p data-testid="state">{session.state}</p><button disabled={!session.ready} onClick={()=>void signInServerSession().catch(()=>setError('Sign-in denied'))}>Sign in</button>
      {session.state==='active'&&<section aria-label="Private workspace"><p>Private workspace</p><button onClick={()=>void read()}>Protected request</button><button onClick={()=>{privateSessionStorage.setItem('wellness.client.bookingIntent','synthetic-private-intent');setData('synthetic-private-intent');}}>Prepare private intent</button><button onClick={()=>void signOutServerSession().catch(()=>setError('Sign-out unconfirmed'))}>Sign out</button><pre>{data}</pre></section>}
      <p role="status">{error}</p></main></ThemeProvider>;
  }
  createRoot(document.getElementById('root')!).render(<Workspace/>);
}
