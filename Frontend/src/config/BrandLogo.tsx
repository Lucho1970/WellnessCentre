import { Box } from '@mui/material';
import { HeartPulse } from 'lucide-react';
import { useClinicConfig } from './ClinicConfigProvider';
import { apiBaseUrl } from '../shared/api';

export function BrandLogo({ size = 28 }: { size?: number }) {
  const { config } = useClinicConfig();
  if (!config.logo_version) return <HeartPulse aria-hidden="true" color="#176b62" size={size}/>;
  return <Box component="img" src={`${apiBaseUrl}/brand/logo?v=${encodeURIComponent(config.logo_version)}`} alt="" sx={{ display: 'block', maxWidth: { xs: 120, sm: 180 }, width: 'auto', height: size, objectFit: 'contain' }}/>
}
