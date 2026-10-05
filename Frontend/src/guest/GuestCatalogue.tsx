import { useEffect, useMemo, useState } from 'react';
import { Alert, Avatar, Box, Button, Card, CardContent, Chip, CircularProgress, Container, Grid, Paper, Stack, Typography } from '@mui/material';
import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiBaseUrl, apiRequest } from '../shared/api';
import { formatCad } from '../i18n/format';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { initials, localized, serviceBookingPath, type PublicPractitioner, type PublicService } from './catalogue';
import { ShareBookingLink } from './ShareBookingLink';
import { PractitionerNameHover } from '../shared/PractitionerPersonCard';

const categoryAnchor = (id: number | null) => `category-${id ?? 'other'}`;
const imageFor = (person: PublicPractitioner) => person.has_image
  ? `${apiBaseUrl}/team/${encodeURIComponent(person.slug)}/image?v=${encodeURIComponent(person.image_version ?? '')}`
  : undefined;

export function GuestCatalogue() {
  const { t, i18n } = useTranslation();
  const { config } = useClinicConfig();
  const location = useLocation();
  const language = i18n.resolvedLanguage ?? 'en';
  const [services, setServices] = useState<PublicService[]>([]);
  const [practitioners, setPractitioners] = useState<PublicPractitioner[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [active, setActive] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true); setError('');
    void Promise.all([
      apiRequest<PublicService[]>('/public/services', { signal: controller.signal }),
      apiRequest<PublicPractitioner[]>('/public/practitioners', { signal: controller.signal }),
    ]).then(([nextServices, nextPractitioners]) => {
      if (!controller.signal.aborted) { setServices(nextServices); setPractitioners(nextPractitioners); }
    }).catch(cause => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load treatments.'));
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, t]);

  const categories = useMemo(() => {
    const groups = new Map<string, { id: number | null; name: string | null; name_fr: string | null; description: string | null; description_fr: string | null; services: PublicService[] }>();
    for (const service of services) {
      const key = categoryAnchor(service.category_id);
      const group = groups.get(key) ?? { id: service.category_id, name: service.category, name_fr: service.category_fr, description: service.category_description, description_fr: service.category_description_fr, services: [] };
      group.services.push(service); groups.set(key, group);
    }
    return [...groups.values()];
  }, [services]);

  useEffect(() => {
    if (!categories.length) return;
    const target = location.hash.slice(1);
    if (target) window.setTimeout(() => document.getElementById(target)?.scrollIntoView(), 0);
  }, [categories, location.hash]);
  useEffect(() => {
    if (!categories.length) return;
    const update = () => {
      let current = categoryAnchor(categories[0].id);
      for (const category of categories) {
        const element = document.getElementById(categoryAnchor(category.id));
        if (element && element.getBoundingClientRect().top <= 150) current = categoryAnchor(category.id);
      }
      setActive(current);
    };
    update(); window.addEventListener('scroll', update, { passive: true });
    return () => window.removeEventListener('scroll', update);
  }, [categories]);

  const title = localized(config.welcome_title_en, config.welcome_title_fr, language) || t('Welcome to {{clinic}}', { clinic: config.name });
  const body = localized(config.welcome_body_en, config.welcome_body_fr, language) || t('Explore our treatments, meet the practitioners who provide them, and choose an available appointment time. You can browse without signing in. Sign in when you are ready to book.');
  const backTo = location.pathname + location.search + location.hash;

  return <>
    <Box component="section" sx={{ background: 'linear-gradient(135deg, rgba(23,107,98,.11), rgba(216,117,76,.09))', py: { xs: 7, md: 10 } }}>
      <Container maxWidth="lg">
        <Typography variant="overline" color="primary" fontWeight={800}>{config.name}</Typography>
        <Typography variant="h2" component="h1" sx={{ mt: 1, maxWidth: 830 }}>{title}</Typography>
        <Typography variant="h6" color="text.secondary" sx={{ mt: 3, maxWidth: 760, whiteSpace: 'pre-line', fontWeight: 400 }}>{body}</Typography>
        <Button href="#treatments" variant="contained" size="large" sx={{ mt: 4 }}>{t('Explore treatments')}</Button>
      </Container>
    </Box>
    <Container maxWidth="lg" sx={{ py: 5 }}><Grid container spacing={2}>{[
      ['Explore treatments', 'Compare descriptions, appointment lengths, and prices before choosing care.'],
      ['Meet practitioners', 'Read public practitioner profiles and see who offers each treatment.'],
    ].map(([heading, description]) => <Grid key={heading} size={{ xs: 12, md: 6 }}><Paper variant="outlined" sx={{ p: 3, height: '100%' }}><Typography variant="h6">{t(heading)}</Typography><Typography color="text.secondary" mt={1}>{t(description)}</Typography></Paper></Grid>)}</Grid></Container>
    <Box id="treatments" component="section" sx={{ scrollMarginTop: 16 }}>
      <Container maxWidth="lg" sx={{ pb: 2 }}><Typography variant="overline" color="primary">{t('Treatments')}</Typography><Typography variant="h3" component="h2">{t('Find the care that fits.')}</Typography></Container>
      {loading && <Container maxWidth="lg"><CircularProgress aria-label={t('Loading treatments')}/></Container>}
      {error && <Container maxWidth="lg"><Alert severity="error" action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert></Container>}
      {!loading && !error && !services.length && <Container maxWidth="lg"><Alert severity="info">{t('Treatments are being prepared.')}</Alert></Container>}
      {categories.length > 0 && <Box component="nav" aria-label={t('Treatment categories')} sx={{ position: 'sticky', top: 0, zIndex: 10, bgcolor: 'background.paper', borderBottom: '1px solid', borderColor: 'divider', boxShadow: 1 }}><Container maxWidth="lg"><Stack direction="row" gap={1} sx={{ py: 1.25, overflowX: 'auto', whiteSpace: 'nowrap' }}>{categories.map(category => {
        const id = categoryAnchor(category.id);
        return <Button key={id} href={`#${id}`} variant={active === id ? 'contained' : 'text'} size="small" aria-current={active === id ? 'location' : undefined}>{localized(category.name, category.name_fr, language) || t('Other treatments')}</Button>;
      })}</Stack></Container></Box>}
      {categories.map((category, index) => <Box component="section" id={categoryAnchor(category.id)} key={categoryAnchor(category.id)} sx={{ scrollMarginTop: 72, py: { xs: 5, md: 7 }, bgcolor: index % 2 ? 'background.default' : 'background.paper' }}><Container maxWidth="lg">
        <Typography variant="h4" component="h3">{localized(category.name, category.name_fr, language) || t('Other treatments')}</Typography>
        {localized(category.description, category.description_fr, language) && <Typography color="text.secondary" sx={{ mt: 1, maxWidth: 850, whiteSpace: 'pre-line' }}>{localized(category.description, category.description_fr, language)}</Typography>}
        <Stack spacing={3} mt={3}>{category.services.map(service => {
          const people = practitioners.filter(person => person.services.some(item => item.slug === service.slug));
          const serviceName = localized(service.name, service.name_fr, language);
          return <Card key={service.slug} variant="outlined" sx={{ borderRadius: 3 }}><CardContent sx={{ p: { xs: 2.5, md: 4 } }}><Grid container spacing={3}>
            <Grid size={{ xs: 12, md: 7 }}>
              <Typography variant="h5" component="h4">{serviceName}</Typography>
              <Typography sx={{ mt: 1.5, whiteSpace: 'pre-line' }} color="text.secondary">{localized(service.public_summary, service.public_summary_fr, language) || localized(service.description, service.description_fr, language)}</Typography>
              {localized(service.public_summary, service.public_summary_fr, language) && localized(service.description, service.description_fr, language) && <Typography sx={{ mt: 1.5, whiteSpace: 'pre-line' }} color="text.secondary">{localized(service.description, service.description_fr, language)}</Typography>}
              <Stack direction="row" gap={1} mt={2} flexWrap="wrap">{service.offers_clinic && <Chip size="small" label={t('In clinic')}/>} {service.offers_mobile && <Chip size="small" label={t('On-Site')}/>}</Stack>
              <Stack direction="row" gap={1} mt={2} flexWrap="wrap">{[...service.durations].sort((a, b) => a.minutes - b.minutes).map(option => <Button key={option.minutes} component={Link} to={serviceBookingPath(service.slug, Number(option.minutes))} size="small" variant="outlined" aria-label={t('Book {{minutes}} minutes of {{service}}', { minutes: Number(option.minutes), service: serviceName })}>{t('{{minutes}} min — {{price}}', { minutes: Number(option.minutes), price: formatCad(Number(option.price_cents), language) })}</Button>)}</Stack>
              <Typography variant="caption" color="text.secondary" display="block" mt={1}>{t('Final price is checked before confirmation; practitioner or On-Site charges may apply.')}</Typography>
              <Button component={Link} to={serviceBookingPath(service.slug)} variant="contained" sx={{ mt: 3, mb: 1.5 }}>{t('Book this treatment')}</Button>
              <ShareBookingLink path={serviceBookingPath(service.slug)} title={serviceName}/>
            </Grid>
            <Grid size={{ xs: 12, md: 5 }}><Typography variant="subtitle1" fontWeight={750}>{t('Practitioners for this treatment')}</Typography>
              {people.length ? <Grid container spacing={1.5} mt={0.5}>{people.map(person => <Grid key={person.slug} size={{ xs: 6, sm: 4 }}><PractitionerNameHover person={person}><Box component={Link} to={`/practitioners/${encodeURIComponent(person.slug)}`} state={{ from: backTo }} sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 0.5, textAlign: 'center', textDecoration: 'none', color: 'inherit', p: 1, borderRadius: 2, '&:hover': { bgcolor: 'action.hover' } }}><Avatar src={imageFor(person)} alt="" sx={{ width: 70, height: 70 }}>{initials(person.public_name)}</Avatar><Typography variant="body2" fontWeight={650}>{person.public_name}</Typography></Box></PractitionerNameHover></Grid>)}</Grid> : <Typography color="text.secondary">{t('Practitioner details are being prepared.')}</Typography>}
            </Grid>
          </Grid></CardContent></Card>;
        })}</Stack>
      </Container></Box>)}
    </Box>
  </>;
}
