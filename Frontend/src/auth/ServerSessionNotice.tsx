import { useEffect, useState, useSyncExternalStore } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { serverSessionsEnabled } from './serverSessionMode';
import { beginSessionRevocation, confirmSessionRevocation, extendServerSession, prepareServerSession, sessionSnapshot, subscribeSession, type SessionRevocation } from './serverSession';

export function ServerSessionNotice() {
  const { t }=useTranslation();const session=useSyncExternalStore(subscribeSession,sessionSnapshot);
  const [now,setNow]=useState(Date.now()),[open,setOpen]=useState(false),[pending,setPending]=useState<SessionRevocation|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false),[submitting,setSubmitting]=useState(false);
  useEffect(()=>{if(!serverSessionsEnabled() || session.state!=='active')return;const timer=window.setInterval(()=>setNow(Date.now()),1000);return()=>window.clearInterval(timer);},[session.state]);
  useEffect(()=>{if(session.state!=='active'){setOpen(false);setPending(null);}},[session.state]);
  useEffect(()=>{
    if(!open)return;let cancelled=false;setPending(null);setError('');setBusy(true);
    void beginSessionRevocation().then(value=>{if(!cancelled)setPending(value);}).catch(()=>{if(!cancelled)setError(t('Unable to confirm session access. Sign in again.'));}).finally(()=>{if(!cancelled)setBusy(false);});
    return()=>{cancelled=true;};
  },[open,t]);
  if(!serverSessionsEnabled())return null;
  const deadline=session.lease?Math.min(session.lease.idle_expires_at,session.lease.absolute_expires_at)*1000:null;
  const warning=deadline!==null && deadline-now<=120000;
  return <Stack spacing={1} sx={{mx:'auto',my:1,maxWidth:1000,px:2}}>
    {session.state==='starting' && <Button onClick={()=>location.reload()}>{t('Cancel sign-in')}</Button>}
    {session.state==='ended' && <Alert severity="warning">{t('Your secure session has ended. Sign in again.')}</Alert>}
    {session.state!=='active' && session.state!=='starting' && !session.ready && <Alert severity="info" action={<Button onClick={()=>{setError('');void prepareServerSession().catch(()=>setError(t('Unable to prepare secure sign-in. Please retry.')));}}>{t('Retry')}</Button>}>{t('Preparing secure sign-in…')}</Alert>}
    {session.notice==='sign-out-unconfirmed' && <Alert severity="error">{t('Server sign-out could not be confirmed. Close this page and contact the clinic.')}</Alert>}
    {error && <Alert severity="error">{error}</Alert>}
    {session.state==='active' && <Stack direction={{xs:'column',sm:'row'}} spacing={1} justifyContent="flex-end">
      {warning && <Alert severity="warning" role="status" action={<Button onClick={()=>void extendServerSession()}>{t('Continue session')}</Button>}>{t('Your secure session will end soon. Save your work.')}</Alert>}
      <Button size="small" onClick={()=>setOpen(true)}>{t('Sign out all sessions for this portal')}</Button>
    </Stack>}
    <Dialog open={open} onClose={()=>{if(!submitting)setOpen(false);}} aria-labelledby="session-revoke-title">
      <DialogTitle id="session-revoke-title">{t('Sign out all sessions for this portal')}</DialogTitle>
      <DialogContent><Typography>{t('Confirm with your original account to end its sessions for this portal and sign-in type. Unsaved work will be cleared.')}</Typography>{busy && <Typography role="status">{t('Preparing secure confirmation…')}</Typography>}</DialogContent>
      <DialogActions sx={{flexWrap:"wrap",gap:1}}><Button disabled={submitting} onClick={()=>setOpen(false)}>{t('Cancel')}</Button><Button disabled={!pending || busy || submitting} onClick={()=>{if(!pending)return;setSubmitting(true);void confirmSessionRevocation(pending).catch(()=>setError(t('Server sign-out could not be confirmed. Close this page and contact the clinic.'))).finally(()=>{setSubmitting(false);setOpen(false);setPending(null);});}}>{t('Confirm sign-out')}</Button></DialogActions>
    </Dialog>
  </Stack>;
}
