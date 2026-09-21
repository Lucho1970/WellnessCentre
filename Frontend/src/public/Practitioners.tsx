import { useEffect, useMemo, useState } from 'react';
import { Alert, Avatar, Box, Button, Card, CardActions, CardContent, Chip, CircularProgress, Container, FormControl, Grid, InputLabel, MenuItem, Select, Stack, Typography } from '@mui/material';
import { ArrowLeft, CalendarDays } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiBaseUrl, apiRequest } from '../shared/api';
import { formatCad } from '../i18n/format';

type Duration = { minutes: number; price_cents: number };
type ServiceSummary = { slug: string; name: string; name_fr: string | null; category: string | null };
type Service = ServiceSummary & {
  public_summary: string | null; public_summary_fr: string | null;
  description: string | null; description_fr: string | null;
  offers_clinic: boolean; offers_mobile: boolean; durations: Duration[];
};
type Practitioner = {
  slug: string; public_name: string; booking_name: string | null;
  public_title: string; public_title_fr: string | null;
  summary: string | null; summary_fr: string | null;
  discipline: string | null; credentials: string | null;
  has_image: boolean; image_version: string | null;
  booking_practitioner_id: number | null;
  services: (ServiceSummary | Service)[];
};

const localized = (english: string | null, french: string | null, language: string) => language.toLowerCase().startsWith('fr') ? french || english || '' : english || french || '';
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('');
const imageFor = (person: Practitioner) => person.has_image ? `${apiBaseUrl}/team/${encodeURIComponent(person.slug)}/image?v=${encodeURIComponent(person.image_version ?? '')}` : undefined;

function PractitionerCard({ person }: { person: Practitioner }) {
  const { t, i18n } = useTranslation();
  const language = i18n.resolvedLanguage ?? 'en';
  const professionalDetails = [person.credentials, person.discipline].filter((value, index, values) => value && values.indexOf(value) === index).join(' · ');
  return <Card variant="outlined" sx={{ height: '100%', borderRadius: 3, display: 'flex', flexDirection: 'column' }}>
    <CardContent sx={{ p: 3, flexGrow: 1 }}>
      <Stack alignItems="center" spacing={1.5} textAlign="center">
        <Avatar src={imageFor(person)} alt="" sx={{ width: 112, height: 112, bgcolor: 'primary.light', color: 'primary.dark', fontSize: '2rem' }}>{initials(person.public_name)}</Avatar>
        <Box><Typography variant="h5" component="h2">{person.public_name}</Typography><Typography color="primary.main" fontWeight={650}>{localized(person.public_title, person.public_title_fr, language)}</Typography></Box>
        {professionalDetails && <Typography variant="body2" color="text.secondary">{professionalDetails}</Typography>}
        {localized(person.summary, person.summary_fr, language) && <Typography>{localized(person.summary, person.summary_fr, language)}</Typography>}
        <Stack direction="row" gap={1} flexWrap="wrap" justifyContent="center">{person.services.slice(0, 4).map(service => <Chip key={service.slug} component={Link} clickable to={`/services/${service.slug}`} size="small" label={localized(service.name, service.name_fr, language)} />)}</Stack>
      </Stack>
    </CardContent>
    <CardActions sx={{ px: 3, pb: 3, pt: 0, flexWrap: 'wrap' }}>
      <Button component={Link} to={`/practitioners/${person.slug}`} sx={{ flexGrow: 1 }}>{t('View profile')}</Button>
      {person.booking_practitioner_id && <Button component={Link} to={`/book?practitioner_id=${person.booking_practitioner_id}`} variant="contained" startIcon={<CalendarDays size={18}/>} aria-label={t('Book with {{name}}', { name: person.public_name })}>{t('Book with {{name}}', { name: person.booking_name || person.public_name })}</Button>}
    </CardActions>
  </Card>;
}

export function PractitionersDirectory() {
  const { t, i18n } = useTranslation();
  const [practitioners, setPractitioners] = useState<Practitioner[]>([]);
  const [service, setService] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void apiRequest<Practitioner[]>('/public/practitioners', { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setPractitioners(data); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load practitioners.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, t]);
  const language = i18n.resolvedLanguage ?? 'en';
  const services = useMemo(() => Array.from(new Map(practitioners.flatMap(person => person.services).map(item => [item.slug, item])).values()), [practitioners]);
  const visible = service === 'all' ? practitioners : practitioners.filter(person => person.services.some(item => item.slug === service));
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Typography variant="overline" color="primary">{t('Our practitioners')}</Typography>
    <Typography variant="h2" component="h1">{t('Meet your care team.')}</Typography>
    <Typography color="text.secondary" fontSize="1.1rem" mt={2} maxWidth={760}>{t('Explore practitioner profiles, the services they offer, and book with the person who feels right for you.')}</Typography>
    {services.length > 0 && <FormControl size="small" sx={{ minWidth: 280, my: 3 }}><InputLabel id="practitioner-service-filter-label">{t('Service')}</InputLabel><Select id="practitioner-service-filter" labelId="practitioner-service-filter-label" label={t('Service')} value={service} onChange={event => setService(event.target.value)}><MenuItem value="all">{t('All services')}</MenuItem>{services.map(item => <MenuItem key={item.slug} value={item.slug}>{localized(item.name, item.name_fr, language)}</MenuItem>)}</Select></FormControl>}
    {loading && <CircularProgress aria-label={t('Loading practitioners')} sx={{ display: 'block', my: 4 }}/>} 
    {error && <Alert severity="error" sx={{ my: 3 }} action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {!loading && !error && practitioners.length === 0 && <Alert severity="info" sx={{ mt: 3 }}>{t('Practitioner profiles are being prepared.')}</Alert>}
    {!loading && !error && practitioners.length > 0 && visible.length === 0 && <Alert severity="info" sx={{ mt: 3 }}>{t('No practitioners offer the selected service.')}</Alert>}
    <Grid container spacing={3} mt={1}>{visible.map(person => <Grid key={person.slug} size={{ xs: 12, sm: 6, md: 4 }}><PractitionerCard person={person}/></Grid>)}</Grid>
  </Container>;
}

export function PractitionerDetails() {
  const { slug = '' } = useParams();
  const { t, i18n } = useTranslation();
  const [person, setPerson] = useState<Practitioner | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void apiRequest<Practitioner>(`/public/practitioners/${encodeURIComponent(slug)}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setPerson(data); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load this practitioner.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [slug, t]);
  if (loading) return <Container sx={{ py: 7 }}><CircularProgress aria-label={t('Loading practitioner')}/></Container>;
  if (error || !person) return <Container sx={{ py: 7 }}><Alert severity="error">{error || t('This practitioner profile is not available.')}</Alert><Button component={Link} to="/practitioners" startIcon={<ArrowLeft/>} sx={{ mt: 2 }}>{t('Back to practitioners')}</Button></Container>;
  const language = i18n.resolvedLanguage ?? 'en';
  const services = person.services as Service[];
  const professionalDetails = [person.credentials, person.discipline].filter((value, index, values) => value && values.indexOf(value) === index).join(' · ');
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Button component={Link} to="/practitioners" startIcon={<ArrowLeft/>}>{t('Back to practitioners')}</Button>
    <Grid container spacing={5} mt={1} alignItems="start"><Grid size={{ xs: 12, md: 4 }}><Stack alignItems={{ xs: 'center', md: 'flex-start' }} spacing={2} textAlign={{ xs: 'center', md: 'left' }}>
      <Avatar src={imageFor(person)} alt="" sx={{ width: { xs: 180, md: 240 }, height: { xs: 180, md: 240 }, bgcolor: 'primary.light', color: 'primary.dark', fontSize: '3rem' }}>{initials(person.public_name)}</Avatar>
      {person.booking_practitioner_id && <Button component={Link} to={`/book?practitioner_id=${person.booking_practitioner_id}`} variant="contained" size="large" startIcon={<CalendarDays/>}>{t('Book with {{name}}', { name: person.booking_name || person.public_name })}</Button>}
    </Stack></Grid><Grid size={{ xs: 12, md: 8 }}>
      <Typography variant="overline" color="primary">{t('Practitioner profile')}</Typography><Typography variant="h2" component="h1">{person.public_name}</Typography><Typography variant="h5" color="primary.main" mt={1}>{localized(person.public_title, person.public_title_fr, language)}</Typography>
      {professionalDetails && <Typography color="text.secondary" mt={1}>{professionalDetails}</Typography>}
      {localized(person.summary, person.summary_fr, language) && <Typography fontSize="1.1rem" sx={{ whiteSpace: 'pre-line' }} mt={3}>{localized(person.summary, person.summary_fr, language)}</Typography>}
      <Typography variant="h4" component="h2" mt={5} mb={2}>{t('Services offered')}</Typography>
      {services.length === 0 ? <Typography color="text.secondary">{t('Contact the clinic for service availability.')}</Typography> : <Grid container spacing={2}>{services.map(item => <Grid key={item.slug} size={{ xs: 12, sm: 6 }}><Card variant="outlined" sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}><CardContent sx={{ flexGrow: 1 }}>{item.category && <Typography variant="overline" color="primary">{item.category}</Typography>}<Typography variant="h5">{localized(item.name, item.name_fr, language)}</Typography><Typography color="text.secondary" mt={1}>{localized(item.public_summary, item.public_summary_fr, language) || localized(item.description, item.description_fr, language)}</Typography><Typography fontWeight={650} mt={2}>{item.durations.map(option => t('{{minutes}} min — {{price}}', { minutes: Number(option.minutes), price: formatCad(Number(option.price_cents), language) })).join(' · ')}</Typography></CardContent><CardActions><Button component={Link} to={`/services/${item.slug}`}>{t('View service')}</Button>{person.booking_practitioner_id && <Button component={Link} to={`/book?service=${encodeURIComponent(item.slug)}&practitioner_id=${person.booking_practitioner_id}`} variant="contained">{t('Book')}</Button>}</CardActions></Card></Grid>)}</Grid>}
    </Grid></Grid>
  </Container>;
}
