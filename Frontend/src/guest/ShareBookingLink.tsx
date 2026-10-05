import { useState } from 'react';
import { Button, Stack, TextField, Typography } from '@mui/material';
import { Copy, Share2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export function ShareBookingLink({ path, title, kind = 'booking' }: { path: string; title: string; kind?: 'booking' | 'profile' }) {
  const { t } = useTranslation();
  const [message, setMessage] = useState('');
  const [showLink, setShowLink] = useState(false);
  const url = new URL(path.replace(/^\//, ''), new URL(import.meta.env.BASE_URL, window.location.origin)).href;
  const isProfile = kind === 'profile';

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setMessage(t(isProfile ? 'Profile link copied.' : 'Booking link copied.'));
      setShowLink(false);
    } catch {
      setMessage(t(isProfile ? 'Select and copy this profile link.' : 'Select and copy this booking link.'));
      setShowLink(true);
    }
  }

  async function share() {
    if (!navigator.share) { await copy(); return; }
    try {
      await navigator.share({ title, url });
      setMessage('');
    } catch (error) {
      if ((error as Error)?.name !== 'AbortError') await copy();
    }
  }

  return <Stack spacing={1}>
    <Stack direction="row" gap={1} flexWrap="wrap">
      <Button size="small" variant="outlined" startIcon={<Share2 size={16}/>} onClick={() => void share()}>{t(isProfile ? 'Share profile' : 'Share booking link')}</Button>
      <Button size="small" startIcon={<Copy size={16}/>} onClick={() => void copy()}>{t('Copy link')}</Button>
    </Stack>
    {message && <Typography role="status" variant="body2">{message}</Typography>}
    {showLink && <TextField size="small" label={t(isProfile ? 'Profile link' : 'Booking link')} value={url} InputProps={{ readOnly: true }} onFocus={event => event.target.select()} />}
  </Stack>;
}
