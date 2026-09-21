import { useEffect, useState } from 'react';
import { Alert, Avatar, Box, Button, Card, CardActions, CardContent, CircularProgress, Container, Grid, Stack, Typography } from '@mui/material';
import { CalendarDays } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiBaseUrl, apiRequest } from '../shared/api';

type TeamMember = {
  slug: string;
  section: 'practitioner' | 'administration';
  public_name: string;
  booking_name: string | null;
  public_title: string;
  public_title_fr: string | null;
  summary: string | null;
  summary_fr: string | null;
  display_order: number;
  has_image: number | boolean;
  image_version: string | null;
  practitioner_id: number | null;
  discipline: string | null;
  credentials: string | null;
};

function initials(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('');
}

function TeamMemberTile({ member }: { member: TeamMember }) {
  const { t, i18n } = useTranslation();
  const image = member.has_image
    ? `${apiBaseUrl}/team/${encodeURIComponent(member.slug)}/image?v=${encodeURIComponent(member.image_version ?? '')}`
    : '';
  const french = i18n.resolvedLanguage?.startsWith('fr');
  const title = french && member.public_title_fr ? member.public_title_fr : member.public_title;
  const summary = french && member.summary_fr ? member.summary_fr : member.summary;
  const professionalDetails = [member.credentials, member.discipline].filter((value, index, values) => value && values.indexOf(value) === index).join(' · ');

  return <Card variant="outlined" sx={{ height: '100%', borderRadius: 3, display: 'flex', flexDirection: 'column' }}>
      <CardContent sx={{ p: 3, flexGrow: 1 }}>
        <Stack alignItems="center" spacing={1.5} textAlign="center" height="100%">
          <Avatar src={image || undefined} alt="" sx={{ width: 112, height: 112, bgcolor: 'primary.light', color: 'primary.dark', fontSize: '2rem' }}>
            {initials(member.public_name)}
          </Avatar>
          <Box><Typography variant="h6" component="h4">{member.public_name}</Typography><Typography color="primary.main" fontWeight={650}>{title}</Typography></Box>
          {professionalDetails && <Typography variant="body2" color="text.secondary">{professionalDetails}</Typography>}
          {summary && <Typography sx={{ whiteSpace: 'pre-line' }}>{summary}</Typography>}
        </Stack>
      </CardContent>
      {member.practitioner_id && <CardActions sx={{ px: 3, pb: 3, pt: 0 }}><Button fullWidth component={Link} to={`/book?practitioner_id=${member.practitioner_id}`} variant="contained" startIcon={<CalendarDays size={18} />} aria-label={t('Book with {{name}}',{name:member.public_name})}>{t('Book with {{name}}',{name:member.booking_name||member.public_name})}</Button></CardActions>}
    </Card>;
}

export function TeamSection() {
  const { t } = useTranslation();
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void apiRequest<TeamMember[]>('/team', { signal: controller.signal })
      .then(data => { if (!controller.signal.aborted) setMembers(data); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load the team.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [retry, t]);
  const sections = [
    { key: 'practitioner' as const, title: t('Practitioners') },
    { key: 'administration' as const, title: t('Administration') },
  ];

  return <Box component="section" aria-labelledby="our-team-heading" sx={{ bgcolor: 'background.default', py: { xs: 6, md: 8 } }}>
    <Container maxWidth="lg">
      <Typography variant="overline" color="primary">{t('People who care')}</Typography>
      <Typography id="our-team-heading" variant="h3" component="h2">{t('Our Team')}</Typography>
      <Typography color="text.secondary" mt={1} maxWidth={720}>{t('Meet the people who support your care and your experience with the clinic.')}</Typography>
      {loading && <CircularProgress sx={{ mt: 4 }} aria-label={t('Loading team')} />}
      {error && <Alert severity="error" sx={{ mt: 3 }} action={<Button color="inherit" onClick={() => setRetry(value => value + 1)}>{t('Retry')}</Button>}>{error}</Alert>}
      {!loading && !error && !members.length && <Typography color="text.secondary" mt={4}>{t('Team profiles are being prepared.')}</Typography>}
      {!loading && !error && sections.map(section => {
        const people = members.filter(member => member.section === section.key);
        return people.length ? <Box key={section.key} mt={5}>
          <Typography variant="h4" component="h3" mb={2.5}>{section.title}</Typography>
          <Grid container spacing={2.5}>{people.map(member => <Grid key={member.slug} size={{ xs: 12, sm: 6, md: 4 }}><TeamMemberTile member={member} /></Grid>)}</Grid>
        </Box> : null;
      })}
    </Container>
  </Box>;
}
