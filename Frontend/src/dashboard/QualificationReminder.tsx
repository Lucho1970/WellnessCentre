import { useEffect, useState } from 'react';
import { Alert, Button, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { pagePath } from '../portal/access';

const api = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
type ExpiringRecord = { id: number; qualification_name: string; status: string; renewal_warning: boolean; days_until_expiry: number | null };

export function QualificationReminder() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [records, setRecords] = useState<ExpiringRecord[]>([]);
  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const token = await getAccessToken();
        const response = await fetch(`${api}/profile/qualifications`, { headers: { Authorization: `Bearer ${token}` } });
        if (!response.ok) return;
        const body = await response.json();
        if (active) setRecords((body.data as ExpiringRecord[]).filter(record => record.renewal_warning));
      } catch { /* Profile displays the actionable error; dashboard remains usable. */ }
    })();
    return () => { active = false; };
  }, [getAccessToken]);
  if (records.length === 0) return null;
  return <Alert severity="warning" action={<Button component={Link} to={pagePath('practitioner', 'profile')} color="inherit">{t('Review qualifications')}</Button>}><Typography fontWeight={700}>{t('{{count}} qualification(s) need renewal attention.', { count: records.length })}</Typography><Typography variant="body2">{records.map(record => record.qualification_name).join(', ')}</Typography></Alert>;
}
