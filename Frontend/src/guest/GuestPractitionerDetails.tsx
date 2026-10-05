import { useEffect, useState } from 'react';
import { Alert, Avatar, Box, Button, Card, CardContent, CircularProgress, Container, Grid, Stack, Typography } from '@mui/material';
import { ArrowLeft } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiBaseUrl, apiRequest } from '../shared/api';
import { formatCad } from '../i18n/format';
import { initials, localized, serviceBookingPath, type PublicPractitioner, type PublicService } from './catalogue';
import { ShareBookingLink } from './ShareBookingLink';
import { PractitionerContactActions } from '../shared/PractitionerPersonCard';

type PractitionerDetails = Omit<PublicPractitioner,'services'> & {services:PublicService[]};
const imageFor=(person:PractitionerDetails)=>person.has_image?`${apiBaseUrl}/team/${encodeURIComponent(person.slug)}/image?v=${encodeURIComponent(person.image_version??'')}`:undefined;

export function GuestPractitionerDetails(){
  const {t,i18n}=useTranslation();const location=useLocation();const slug=location.pathname.split('/').at(-1)??'';
  const [person,setPerson]=useState<PractitionerDetails|null>(null);const [loading,setLoading]=useState(true);const [error,setError]=useState('');
  useEffect(()=>{const controller=new AbortController();setLoading(true);setError('');void apiRequest<PractitionerDetails>(`/public/practitioners/${encodeURIComponent(slug)}`,{signal:controller.signal}).then(data=>{if(!controller.signal.aborted)setPerson(data);}).catch(cause=>{if(!controller.signal.aborted)setError(cause instanceof Error?cause.message:t('Unable to load this practitioner.'));}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});return()=>controller.abort();},[slug,t]);
  const from=(location.state as {from?:string}|null)?.from;
  const back=from?.startsWith('/')&&!from.startsWith('//')?from:'/';
  const language=i18n.resolvedLanguage??'en';
  return <Container maxWidth="lg" component="main" id="main-content" tabIndex={-1} sx={{py:{xs:4,md:7}}}>
    <Button component={Link} to={back} startIcon={<ArrowLeft size={18}/>}>{t('Back to treatments')}</Button>
    {loading&&<CircularProgress sx={{display:'block',mt:4}} aria-label={t('Loading practitioner')}/>}
    {error&&<Alert severity="error" sx={{mt:3}}>{error}</Alert>}
    {!loading&&!error&&person&&<>
      <Grid container spacing={5} sx={{mt:2}}><Grid size={{xs:12,md:4}}><Stack alignItems={{xs:'center',md:'flex-start'}}><Avatar src={imageFor(person)} alt="" sx={{width:{xs:180,md:250},height:{xs:180,md:250},fontSize:'3rem'}}>{initials(person.public_name)}</Avatar></Stack></Grid><Grid size={{xs:12,md:8}}><Typography variant="overline" color="primary">{t('Practitioner profile')}</Typography><Typography variant="h2" component="h1">{person.public_name}</Typography><Typography variant="h5" color="primary.main" mt={1}>{localized(person.public_title,person.public_title_fr,language)}</Typography>{[person.credentials,person.discipline].filter(Boolean).length>0&&<Typography color="text.secondary" mt={1}>{[person.credentials,person.discipline].filter(Boolean).join(' · ')}</Typography>}{localized(person.summary,person.summary_fr,language)&&<Typography sx={{whiteSpace:'pre-line',mt:3}}>{localized(person.summary,person.summary_fr,language)}</Typography>}<Box sx={{mt:3}}><PractitionerContactActions person={person}/></Box><Box sx={{mt:3}}><ShareBookingLink path={location.pathname} title={person.public_name} kind="profile"/></Box>{person.booking_practitioner_id&&<Button component={Link} to={`/availability?practitioner_id=${person.booking_practitioner_id}`} variant="contained" sx={{mt:3}}>{t('Book with {{name}}',{name:person.public_name})}</Button>}</Grid></Grid>
      <Typography variant="h4" component="h2" mt={7} mb={3}>{t('Services offered')}</Typography>
      {!person.services.length&&<Typography color="text.secondary">{t('Contact the clinic for service availability.')}</Typography>}
      <Grid container spacing={2}>{person.services.map(service=><Grid key={service.slug} size={{xs:12,md:6}}><Card variant="outlined" sx={{height:'100%'}}><CardContent><Typography variant="h5">{localized(service.name,service.name_fr,language)}</Typography><Typography color="text.secondary" sx={{mt:1,whiteSpace:'pre-line'}}>{localized(service.public_summary,service.public_summary_fr,language)||localized(service.description,service.description_fr,language)}</Typography><Stack direction="row" gap={1} flexWrap="wrap" mt={2}>{[...service.durations].sort((a,b)=>a.minutes-b.minutes).map(option=><Typography key={option.minutes} variant="body2" fontWeight={700}>{t('{{minutes}} min — {{price}}',{minutes:Number(option.minutes),price:formatCad(Number(option.price_cents),language)})}</Typography>)}</Stack>{person.booking_practitioner_id&&<Button component={Link} to={serviceBookingPath(service.slug,undefined,person.booking_practitioner_id)} variant="contained" sx={{mt:2}}>{t('Book this treatment')}</Button>}</CardContent></Card></Grid>)}</Grid>
    </>}
    <Box mt={6}><Button component={Link} to={back} startIcon={<ArrowLeft size={18}/>}>{t('Back to treatments')}</Button></Box>
  </Container>;
}
