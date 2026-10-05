import { useMemo, useState, type PropsWithChildren } from 'react';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { ClinicConfigProvider, useClinicConfig } from '../config/ClinicConfigProvider';
import { UnsavedChangesProvider } from './UnsavedChanges';
import '../i18n';

function ClinicTheme({ children }: PropsWithChildren) {
  const { config } = useClinicConfig();
  const portal = __APP_SURFACE__ === 'portal';
  const theme = useMemo(() => createTheme({
    palette: { primary: { main: portal ? config.theme_primary_color || '#176b62' : '#176b62' }, secondary: { main: portal ? config.theme_secondary_color || '#d8754c' : '#d8754c' }, background: { default: '#f7faf8', paper: '#fff' } },
    typography: { fontFamily: portal ? `${config.theme_font_family || 'Inter'}, system-ui, sans-serif` : 'Inter, system-ui, sans-serif', h1: { fontWeight: 750 }, h2: { fontWeight: 700 } },
    shape: { borderRadius: 14 },
  }), [config.theme_primary_color, config.theme_secondary_color, config.theme_font_family, portal]);
  return <ThemeProvider theme={theme}><CssBaseline/>{children}</ThemeProvider>;
}
export function AppProviders({ children }: PropsWithChildren) {
  const [router] = useState(() => createBrowserRouter([{
    path: '*',
    element: <UnsavedChangesProvider>{children}</UnsavedChangesProvider>,
  }], { basename: import.meta.env.BASE_URL }));
  return <ClinicConfigProvider><ClinicTheme><RouterProvider router={router} /></ClinicTheme></ClinicConfigProvider>;
}
