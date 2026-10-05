import { Button, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { publicLink } from './urls';

export function BetaFeedbackLink() {
  const { config } = useClinicConfig();
  const { t } = useTranslation();
  const release = import.meta.env.VITE_RELEASE_NAME?.trim() ?? '';
  const email = config.email?.trim() ?? '';
  const hasEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  const feedbackHref = () => {
    if (!hasEmail) return publicLink('contact');
    const subject = t('Beta feedback for {{site}}', { site: config.name });
    // Never include the query string or fragment: sign-in and invitation URLs may contain secrets.
    const body = [
      t('Page: {{page}}', { page: window.location.pathname }),
      t('Time (UTC): {{time}}', { time: new Date().toISOString() }),
      ...(release ? [t('Release: {{release}}', { release })] : []),
      '',
      t('What were you trying to do?'),
      '',
      t('What happened?'),
      '',
      t('What did you expect?'),
      '',
      t('Reference ID (if shown):'),
      '',
      t('Please do not include passwords, medical information, or another person’s details.'),
    ].join('\n');
    return `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  };
  return <Stack spacing={0.5} alignItems="flex-start">
    <Button variant="outlined" href={feedbackHref()} onClick={event => { event.currentTarget.href = feedbackHref(); }}>{t('Report a problem')}</Button>
    <Typography variant="caption" color="text.secondary">{t('For beta feedback, describe the steps and any reference ID. Do not send sensitive information.')}</Typography>
    {release && <Typography variant="caption" color="text.secondary">{t('Release: {{release}}', { release })}</Typography>}
  </Stack>;
}
