import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import App from './App';
import './styles.css';
import { StaffAuthProvider, msalInstance } from './auth/AuthProvider';

const theme = createTheme({ palette: { primary: { main: '#176b62' }, secondary: { main: '#d8754c' }, background: { default: '#f7faf8', paper: '#fff' } }, typography: { fontFamily: 'Inter, system-ui, sans-serif', h1: { fontWeight: 750 }, h2: { fontWeight: 700 } }, shape: { borderRadius: 14 } });
async function bootstrap() {
  await msalInstance.initialize();
  const redirectResult = await msalInstance.handleRedirectPromise();
  if (redirectResult?.account) msalInstance.setActiveAccount(redirectResult.account);
  else if (msalInstance.getAllAccounts()[0]) msalInstance.setActiveAccount(msalInstance.getAllAccounts()[0]);
  createRoot(document.getElementById('root')!).render(<React.StrictMode><StaffAuthProvider><ThemeProvider theme={theme}><CssBaseline/><BrowserRouter><App/></BrowserRouter></ThemeProvider></StaffAuthProvider></React.StrictMode>);
}

void bootstrap();
