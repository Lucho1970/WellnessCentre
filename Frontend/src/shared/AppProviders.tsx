import { useState, type PropsWithChildren } from 'react';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { ClinicConfigProvider } from '../config/ClinicConfigProvider';
import { UnsavedChangesProvider } from './UnsavedChanges';
import '../i18n';

const theme = createTheme({
  palette: { primary: { main: '#176b62' }, secondary: { main: '#d8754c' }, background: { default: '#f7faf8', paper: '#fff' } },
  typography: { fontFamily: 'Inter, system-ui, sans-serif', h1: { fontWeight: 750 }, h2: { fontWeight: 700 } },
  shape: { borderRadius: 14 },
});
export function AppProviders({ children }: PropsWithChildren) {
  const [router] = useState(() => createBrowserRouter([{
    path: '*',
    element: <UnsavedChangesProvider>{children}</UnsavedChangesProvider>,
  }], { basename: import.meta.env.BASE_URL }));
  return <ThemeProvider theme={theme}><CssBaseline /><ClinicConfigProvider>
    <RouterProvider router={router} />
  </ClinicConfigProvider></ThemeProvider>;
}
