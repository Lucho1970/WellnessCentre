import { Button } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { publicLink, publicUrl } from './urls';

export function WellnexaPrivacyLink() {
  const { t } = useTranslation();
  if (window.location.hostname !== 'wellnexa.copihue.ca' || publicUrl.origin !== 'https://wellnexa.ca') return null;
  return <Button href={publicLink('privacy')} size="small">{t('Privacy statement')}</Button>;
}
