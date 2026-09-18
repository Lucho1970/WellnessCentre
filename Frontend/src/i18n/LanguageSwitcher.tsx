import { Button } from '@mui/material';
import { Languages } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export function LanguageSwitcher() {
  const { t, i18n } = useTranslation();
  const french = i18n.resolvedLanguage === 'fr';
  const next = french ? 'en' : 'fr';
  const nextName = t(french ? 'English' : 'French');
  return <Button
    color="inherit"
    size="small"
    startIcon={<Languages size={16} />}
    aria-label={t('Switch language to {{language}}', { language: nextName })}
    onClick={() => void i18n.changeLanguage(next)}
  >{french ? 'EN' : 'FR'}</Button>;
}
