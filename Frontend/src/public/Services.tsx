import { useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Card, CardActions, CardContent, Chip, CircularProgress, Container, FormControl, Grid, InputLabel, MenuItem, Select, Stack, Typography } from '@mui/material';
import { ArrowLeft, CalendarDays, MapPin } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiRequest } from '../shared/api';
import { formatCad } from '../i18n/format';

type Duration = { minutes: number; price_cents: number };
type Practitioner = { slug: string; public_name: string; booking_name: string | null; public_title: string; public_title_fr: string | null; summary: string | null; summary_fr: string | null; booking_practitioner_id: number };
type Service = {
  slug: string; name: string; name_fr: string | null; category: string | null;
  public_summary: string | null; public_summary_fr: string | null; description: string | null; description_fr: string | null;
  preparation_instructions: string | null; preparation_instructions_fr: string | null;
  offers_clinic: boolean; offers_mobile: boolean; durations: Duration[];
  practitioners?: Practitioner[]; locations?: { name: string; city: string | null; province: string | null }[];
};

const localized = (english: string | null, french: string | null, language: string) => language.toLowerCase().startsWith('fr') ? french || english || '' : english || french || '';

export function ServicesDirectory() {
  const { t, i18n } = useTranslation();
  const [services, setServices] = useState<Service[]>([]);
  const [category, setCategory] = useState('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void apiRequest<Service[]>('/public/services', { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setServices(data); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load services.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, t]);
  const categories = useMemo(() => [...new Set(services.map(service => service.category).filter((value): value is string => Boolean(value)))], [services]);
  const visible = category === 'all' ? services : services.filter(service => (service.category ?? 'uncategorized') === category);
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Typography variant="overline" color="primary">{t('Our services')}</Typography>
    <Typography variant="h2" component="h1">{t('Find the care that fits you.')}</Typography>
    <Typography color="text.secondary" fontSize="1.1rem" mt={2} maxWidth={760}>{t('Compare treatment options, prices, appointment formats, and the practitioners who provide them.')}</Typography>
    {categories.length > 0 && <FormControl size="small" sx={{ minWidth: 240, my: 3 }}><InputLabel id="service-category-filter-label">{t('Category')}</InputLabel><Select id="service-category-filter" labelId="service-category-filter-label" label={t('Category')} value={category} onChange={event => setCategory(event.target.value)}><MenuItem value="all">{t('All categories')}</MenuItem>{categories.map(item => <MenuItem key={item} value={item}>{item}</MenuItem>)}</Select></FormControl>}
    {loading && <CircularProgress aria-label={t('Loading services')} sx={{ display: 'block', my: 4 }}/>}
    {error && <Alert severity="error" sx={{ my: 3 }} action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
    {!loading && !error && services.length === 0 && <Alert severity="info" sx={{ mt: 3 }}>{t('No services have been published yet.')}</Alert>}
    <Grid container spacing={3} mt={1}>{visible.map(service => <Grid key={service.slug} size={{ xs: 12, sm: 6, md: 4 }}><Card variant="outlined" sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}><CardContent sx={{ flex: 1 }}>
      <Stack direction="row" gap={1} flexWrap="wrap" mb={2}>{service.category && <Chip size="small" label={service.category}/>} {service.offers_clinic && <Chip size="small" variant="outlined" label={t('In clinic')}/>} {service.offers_mobile && <Chip size="small" variant="outlined" label={t('On-Site')}/>}</Stack>
      <Typography variant="h5" component="h2">{localized(service.name, service.name_fr, i18n.resolvedLanguage ?? 'en')}</Typography>
      <Typography color="text.secondary" mt={1}>{localized(service.public_summary, service.public_summary_fr, i18n.resolvedLanguage ?? 'en') || localized(service.description, service.description_fr, i18n.resolvedLanguage ?? 'en')}</Typography>
      <Typography mt={2} fontWeight={650}>{service.durations.map(option => t('{{minutes}} min — {{price}}', { minutes: Number(option.minutes), price: formatCad(Number(option.price_cents), i18n.resolvedLanguage) })).join(' · ')}</Typography>
    </CardContent><CardActions sx={{ p: 2, pt: 0 }}><Button component={Link} to={`/services/${service.slug}`}>{t('View service')}</Button><Button component={Link} to={`/book?service=${encodeURIComponent(service.slug)}`} variant="contained">{t('Book')}</Button></CardActions></Card></Grid>)}</Grid>
  </Container>;
}

export function ServiceDetails() {
  const { slug = '' } = useParams();
  const { t, i18n } = useTranslation();
  const [service, setService] = useState<Service | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void apiRequest<Service>(`/public/services/${encodeURIComponent(slug)}`, { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setService(data); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load this service.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [slug, t]);
  if (loading) return <Container sx={{ py: 7 }}><CircularProgress aria-label={t('Loading service')}/></Container>;
  if (error || !service) return <Container sx={{ py: 7 }}><Alert severity="error">{error || t('This service is not available.')}</Alert><Button component={Link} to="/services" startIcon={<ArrowLeft/>} sx={{ mt: 2 }}>{t('Back to services')}</Button></Container>;
  const language=i18n.resolvedLanguage ?? 'en';
  const name=localized(service.name,service.name_fr,language);
  return <Container maxWidth="lg" sx={{ py: { xs: 4, md: 7 } }}>
    <Button component={Link} to="/services" startIcon={<ArrowLeft/>}>{t('Back to services')}</Button>
    <Grid container spacing={5} mt={1}><Grid size={{ xs: 12, md: 8 }}><Stack spacing={3}>
      <Box>{service.category && <Typography variant="overline" color="primary">{service.category}</Typography>}<Typography variant="h2" component="h1">{name}</Typography><Typography fontSize="1.15rem" color="text.secondary" mt={2}>{localized(service.public_summary,service.public_summary_fr,language)}</Typography></Box>
      <Box><Typography variant="h5" component="h2">{t('About this service')}</Typography><Typography sx={{ whiteSpace: 'pre-line' }} mt={1}>{localized(service.description,service.description_fr,language)}</Typography></Box>
      {localized(service.preparation_instructions,service.preparation_instructions_fr,language) && <Box><Typography variant="h5" component="h2">{t('Before your appointment')}</Typography><Typography sx={{ whiteSpace: 'pre-line' }} mt={1}>{localized(service.preparation_instructions,service.preparation_instructions_fr,language)}</Typography></Box>}
      <Box><Typography variant="h5" component="h2">{t('Practitioners')}</Typography>{service.practitioners?.length ? <Grid container spacing={2} mt={0}>{service.practitioners.map(person => <Grid key={person.slug} size={{ xs: 12, sm: 6 }}><Card variant="outlined"><CardContent><Typography variant="h6">{person.public_name}</Typography><Typography color="primary">{localized(person.public_title,person.public_title_fr,language)}</Typography><Typography color="text.secondary" mt={1}>{localized(person.summary,person.summary_fr,language)}</Typography></CardContent><CardActions><Button component={Link} to={`/book?service=${encodeURIComponent(service.slug)}&practitioner_id=${person.booking_practitioner_id}`} aria-label={t('Book with {{name}}',{name:person.public_name})}>{t('Book with {{name}}',{name:person.booking_name||person.public_name})}</Button></CardActions></Card></Grid>)}</Grid> : <Typography color="text.secondary" mt={1}>{t('Contact the clinic for practitioner availability.')}</Typography>}</Box>
    </Stack></Grid><Grid size={{ xs: 12, md: 4 }}><Card variant="outlined" sx={{ position: { md: 'sticky' }, top: { md: 100 } }}><CardContent><Typography variant="h5">{t('Appointment options')}</Typography><Stack spacing={1.5} mt={2}>{service.durations.map(option => <Stack key={option.minutes} direction="row" justifyContent="space-between"><Typography>{t('{{minutes}} minutes',{minutes:Number(option.minutes)})}</Typography><Typography fontWeight={700}>{formatCad(Number(option.price_cents),language)}</Typography></Stack>)}</Stack><Stack direction="row" gap={1} flexWrap="wrap" mt={3}>{service.offers_clinic && <Chip icon={<MapPin size={15}/>} label={t('In clinic')}/>} {service.offers_mobile && <Chip icon={<MapPin size={15}/>} label={t('On-Site')}/>}</Stack>{service.locations && service.locations.length > 0 && <Box mt={3}><Typography fontWeight={700}>{t('Available locations')}</Typography>{service.locations.map(location => <Typography key={`${location.name}-${location.city}`} color="text.secondary">{[location.name,location.city,location.province].filter(Boolean).join(' · ')}</Typography>)}</Box>}</CardContent><CardActions sx={{ p: 2 }}><Button fullWidth component={Link} to={`/book?service=${encodeURIComponent(service.slug)}`} variant="contained" startIcon={<CalendarDays/>}>{t('Book this service')}</Button></CardActions></Card></Grid></Grid>
  </Container>;
}
