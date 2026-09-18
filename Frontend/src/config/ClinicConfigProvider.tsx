import { createContext, useCallback, useContext, useEffect, useMemo, useState, type PropsWithChildren } from 'react';
import { apiErrorMessage } from '../shared/api';

export type ClinicConfig = {
  name: string;
  legal_name: string | null;
  email: string | null;
  phone: string | null;
};

const fallbackConfig: ClinicConfig = { name: 'Wellness Centre', legal_name: null, email: null, phone: null };
const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';

type ClinicConfigValue = {
  config: ClinicConfig;
  loading: boolean;
  refresh: () => Promise<void>;
};

const ClinicConfigContext = createContext<ClinicConfigValue | null>(null);

export function ClinicConfigProvider({ children }: PropsWithChildren) {
  const [config, setConfig] = useState<ClinicConfig>(fallbackConfig);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const response = await fetch(`${apiBaseUrl}/site-config`);
      const body = await response.json();
      if (!response.ok) throw new Error(apiErrorMessage(body, response.status));
      setConfig(body.data);
      document.title = body.data.name;
    } catch {
      setConfig(fallbackConfig);
      document.title = fallbackConfig.name;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void refresh(); }, [refresh]);
  const value = useMemo(() => ({ config, loading, refresh }), [config, loading, refresh]);
  return <ClinicConfigContext.Provider value={value}>{children}</ClinicConfigContext.Provider>;
}

export function useClinicConfig() {
  const value = useContext(ClinicConfigContext);
  if (!value) throw new Error('useClinicConfig must be used within ClinicConfigProvider.');
  return value;
}
