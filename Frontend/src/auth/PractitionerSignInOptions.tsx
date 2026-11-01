import { Button, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { practitionerSocialSignInEnabled, type PractitionerProvider } from './practitionerIdentity';

export function PractitionerSignInOptions({ disabled, onSignIn, fallbackLabel }: {
  disabled: boolean;
  onSignIn: (provider?: PractitionerProvider) => void;
  fallbackLabel: string;
}) {
  const { t } = useTranslation();
  return <Stack spacing={1} sx={{ width: '100%', maxWidth: 420 }}>
    {practitionerSocialSignInEnabled && <>
      <Typography>{t('Use your own Google or personal Microsoft account. A clinic-issued account is not required.')}</Typography>
      <Button variant="contained" disabled={disabled} onClick={() => onSignIn('google')}>{t('Continue with Google')}</Button>
      <Button variant="outlined" disabled={disabled} onClick={() => onSignIn('microsoft')}>{t('Continue with personal Microsoft account')}</Button>
    </>}
    <Button variant={practitionerSocialSignInEnabled ? 'text' : 'contained'} disabled={disabled} onClick={() => onSignIn()}>{t(practitionerSocialSignInEnabled ? 'Choose another sign-in method' : fallbackLabel)}</Button>
    <Typography variant="body2" color="text.secondary">{t('Use the same sign-in account each time. Clinic approval is required before practitioner access is granted.')}</Typography>
  </Stack>;
}
