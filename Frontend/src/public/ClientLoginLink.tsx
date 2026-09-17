import { useEffect, useRef, useState } from 'react';
import { Avatar, Button, IconButton } from '@mui/material';
import { portalLink, portalUrl } from '../shared/urls';

export function ClientLoginLink() {
  const frame = useRef<HTMLIFrameElement>(null);
  const nonce = useRef('');
  const [initials, setInitials] = useState('');
  const request = () => {
    nonce.current = crypto.randomUUID();
    setInitials('');
    frame.current?.contentWindow?.postMessage({ type: 'wellness:account-request', nonce: nonce.current }, portalUrl.origin);
  };
  useEffect(() => {
    const receive = (event: MessageEvent) => {
      if (event.origin !== portalUrl.origin || event.source !== frame.current?.contentWindow) return;
      if (event.data?.type === 'wellness:account-ready') { request(); return; }
      if (event.data?.type !== 'wellness:account-display' || event.data.nonce !== nonce.current) return;
      const value = event.data.initials;
      if (typeof value === 'string' && value.length <= 8) setInitials(value);
    };
    window.addEventListener('message', receive);
    window.addEventListener('pageshow', request);
    window.addEventListener('focus', request);
    return () => {
      window.removeEventListener('message', receive);
      window.removeEventListener('pageshow', request);
      window.removeEventListener('focus', request);
    };
  }, []);
  return <>
    <iframe ref={frame} src={portalLink('client/session')} title="Client account status" hidden onLoad={request} />
    {initials ? <IconButton href={portalLink('client')} aria-label="Open client account"><Avatar sx={{ width: 36, height: 36, bgcolor: 'primary.main' }}>{initials}</Avatar></IconButton>
      : <Button href={portalLink('client')} variant="contained">Login</Button>}
  </>;
}
