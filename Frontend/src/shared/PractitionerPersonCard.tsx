import type { ReactElement } from 'react';
import { Avatar, Box, Button, Paper, Stack, Tooltip, Typography } from '@mui/material';
import { Globe, Mail, MessageSquare, Phone } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { apiBaseUrl } from './api';
import { initials, localized, type PublicPractitioner } from '../guest/catalogue';

type Person = Omit<Pick<PublicPractitioner, 'slug' | 'public_name' | 'public_title' | 'public_title_fr' | 'summary' | 'summary_fr' | 'discipline' | 'credentials' | 'has_image' | 'image_version' | 'public_website_url' | 'public_contact_email' | 'public_contact_phone' | 'public_contact_sms'>, 'has_image'> & { has_image: boolean | number };

export function PractitionerContactActions({ person }: { person: Pick<Person, 'public_website_url' | 'public_contact_email' | 'public_contact_phone' | 'public_contact_sms'> }) {
  const { t } = useTranslation();
  const email = person.public_contact_email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(person.public_contact_email) ? person.public_contact_email : null;
  const phone = person.public_contact_phone && /^\+[1-9][0-9]{7,14}$/.test(person.public_contact_phone) ? person.public_contact_phone : null;
  let website: string | null = null;
  try {
    const url = new URL(person.public_website_url ?? '');
    if (['https:', 'http:'].includes(url.protocol) && !url.username && !url.password) website = url.href;
  } catch { /* Empty or invalid links are omitted. */ }
  if (!email && !phone && !website) return null;
  return <Stack direction="row" gap={0.5} flexWrap="wrap">
    {website && <Button size="small" href={website} target="_blank" rel="noopener noreferrer" startIcon={<Globe size={15}/>}>{t('Website / social page')}</Button>}
    {phone && <Button size="small" href={`tel:${phone}`} startIcon={<Phone size={15}/>}>{t('Call')}</Button>}
    {phone && Boolean(Number(person.public_contact_sms)) && <Button size="small" href={`sms:${phone}`} startIcon={<MessageSquare size={15}/>}>{t('Text')}</Button>}
    {email && <Button size="small" href={`mailto:${email}`} startIcon={<Mail size={15}/>}>{t('Email')}</Button>}
  </Stack>;
}

export function PractitionerPersonCard({ person }: { person: Person }) {
  const { t, i18n } = useTranslation();
  const language = i18n.resolvedLanguage ?? 'en';
  const title = localized(person.public_title, person.public_title_fr, language);
  const bio = localized(person.summary, person.summary_fr, language);
  const details = [person.credentials, person.discipline].filter((value, index, list) => value && list.indexOf(value) === index).join(' · ');
  const image = Boolean(Number(person.has_image)) ? `${apiBaseUrl}/team/${encodeURIComponent(person.slug)}/image?v=${encodeURIComponent(person.image_version ?? '')}` : undefined;
  return <Paper variant="outlined" elevation={6} sx={{ width: { xs: 290, sm: 340 }, maxWidth: 'calc(100vw - 32px)', p: 2.5, borderRadius: 3, boxShadow: 6, color: 'text.primary' }}>
    <Stack direction="row" spacing={1.5} alignItems="center">
      <Avatar src={image} alt="" sx={{ width: 72, height: 72, bgcolor: 'primary.light', color: 'primary.dark', fontSize: 26 }}>{initials(person.public_name)}</Avatar>
      <Box><Typography fontWeight={800} fontSize="1.1rem">{person.public_name}</Typography>{title && <Typography color="primary.main" fontWeight={650} variant="body2">{title}</Typography>}{details && <Typography color="text.secondary" variant="caption">{details}</Typography>}</Box>
    </Stack>
    {bio && <Typography variant="body2" sx={{ mt: 2, whiteSpace: 'pre-line', maxHeight: 150, overflow: 'auto' }}>{bio}</Typography>}
    <Stack direction="row" gap={0.5} flexWrap="wrap" mt={2}>
      <PractitionerContactActions person={person}/>
      <Button size="small" component={Link} to={`/practitioners/${encodeURIComponent(person.slug)}`}>{t('View profile')}</Button>
    </Stack>
  </Paper>;
}

export function PractitionerNameHover({ person, children }: { person: Person; children: ReactElement }) {
  return <Tooltip arrow enterDelay={700} enterNextDelay={350} leaveDelay={250} enterTouchDelay={600}
    title={<PractitionerPersonCard person={person}/>}
    slotProps={{ tooltip: { sx: { bgcolor: 'transparent', p: 0, maxWidth: 'none' } } }}>
    {children}
  </Tooltip>;
}
