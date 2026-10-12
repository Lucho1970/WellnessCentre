import { Alert, TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useEffect, useState } from 'react';
import { Button, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from './AuthProvider';
import { apiRequest } from '../shared/api';

function StaffInvitationPageForm() {
  const formValidation = useFormValidation();
  const {t}=useTranslation();const {isAuthenticated,configured,signIn,getAccessToken}=useStaffAuth();
  const [name,setName]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState(''),[pending,setPending]=useState(false);
  const [code,setCode]=useState('');
  const [token,setToken]=useState(sessionStorage.getItem('wellness.staff.invitation'));
  useEffect(()=>{
    const capture=()=>{const next=new URLSearchParams(window.location.hash.slice(1)).get('token');if(next&&/^[a-f0-9]{64}$/.test(next)){sessionStorage.setItem('wellness.staff.invitation',next);history.replaceState(null,'',window.location.pathname);setToken(next);setPending(false);setCode('');(formValidation.clear(), setError(''));}};
    window.addEventListener('hashchange',capture);capture();return()=>window.removeEventListener('hashchange',capture);
  },[]);
  const act=async()=>{setBusy(true);(formValidation.clear(), setError(''));try{if(!isAuthenticated){await signIn();return;}const bearer=await getAccessToken();const result=await apiRequest<{verification_code:string}>('/staff-invitations/claim',{method:'POST',headers:{Authorization:`Bearer ${bearer}`,'Content-Type':'application/json'},body:JSON.stringify({token,claimant_name:name})});setCode(result.verification_code);setPending(true);}catch(e){ formValidation.capture(e);setError(e instanceof Error?e.message:t('Sign-in failed.'));}finally{setBusy(false);}};
  return <Paper variant="outlined" sx={{p:4,maxWidth:650,mx:'auto'}}><Stack spacing={2}>
    <Typography variant="h4" component="h1">{t('Practitioner invitation')}</Typography>
    <Typography>{t('Sign in or register, then submit your invitation for clinic approval. This does not publish your profile or grant access immediately.')}</Typography>
    {!configured&&<Alert severity="warning">{t('Invited staff sign-in is not configured.')}</Alert>}
    {!token&&<Alert severity="warning">{t('Open the invitation link supplied by the clinic.')}</Alert>}
    {error&&<Alert severity="error">{error}</Alert>}
    {pending?<><Alert severity="info">{t('Your claim is waiting for clinic approval. Contact the clinic to verify your identity.')}</Alert><TextField name="code" label={t('Verification code')} value={code} slotProps={{input:{readOnly:true}}}/><Typography>{t('Give this code to the clinic through your usual contact method. After approval, continue to staff sign-in.')}</Typography><Button href={`${import.meta.env.BASE_URL}staff/external`} onClick={()=>sessionStorage.removeItem('wellness.staff.invitation')}>{t('Continue to staff sign-in')}</Button></>:<>
      {isAuthenticated&&<TextField name="name" label={t('Your name')} value={name} onChange={e=>setName(e.target.value)} inputProps={{maxLength:150}}/>}
      <Button disabled={busy||!configured||!token||(isAuthenticated&&!name.trim())} onClick={()=>void act()} variant="contained">{t(isAuthenticated?'Submit invitation claim':'Sign in or register as invited staff')}</Button>
    </>}
    <Button href={`${import.meta.env.BASE_URL}staff/login`}>{t('Existing workforce staff sign-in')}</Button>
  </Stack></Paper>;
}
export const StaffInvitationPage = withFormValidation(StaffInvitationPageForm);
