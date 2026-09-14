import { useState, type MouseEvent } from 'react';
import { Avatar, Box, Button, CircularProgress, Divider, IconButton, ListItemIcon, Menu, MenuItem, Typography } from '@mui/material';
import { ExternalLink, LogIn, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { useStaffAuth } from './AuthProvider';

function initials(name:string){return name.split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]).join('').toUpperCase()||'?';}
function openPortal(page:string){const url=new URL(window.location.href);url.searchParams.set('portal',page);window.history.replaceState({},'',`${url.pathname}${url.search}#portal`);window.dispatchEvent(new CustomEvent('portal-navigate',{detail:page}));document.getElementById('portal')?.scrollIntoView();}

export function UserAccountMenu(){
  const {account,configured,isAuthenticated,signIn,signOut}=useStaffAuth();const [anchor,setAnchor]=useState<HTMLElement|null>(null);const [busy,setBusy]=useState(false);const [error,setError]=useState('');
  const run=async(action:()=>Promise<void>)=>{setBusy(true);setError('');try{await action();}catch(cause){setError(cause instanceof Error?cause.message:'Account action failed.');setBusy(false);}};
  if(!isAuthenticated)return <Button href="#portal" variant="contained" size="small" startIcon={<LogIn size={17}/>}>Staff sign in</Button>;
  const name=account?.name??account?.username??'Staff member';
  const choose=(page:string)=>{setAnchor(null);openPortal(page);};
  return <>
    <IconButton aria-label={`Open account menu for ${name}`} aria-controls={anchor?'staff-account-menu':undefined} aria-haspopup="menu" aria-expanded={anchor?'true':undefined} onClick={(event:MouseEvent<HTMLElement>)=>setAnchor(event.currentTarget)} sx={{p:.5}}>
      <Avatar sx={{width:36,height:36,bgcolor:'primary.main',fontSize:14,fontWeight:800}}>{initials(name)}</Avatar>
    </IconButton>
    <Menu id="staff-account-menu" anchorEl={anchor} open={Boolean(anchor)} onClose={()=>setAnchor(null)} slotProps={{paper:{sx:{width:285,mt:1}}}}>
      <Box px={2} py={1}><Typography fontWeight={750}>{name}</Typography><Typography variant="body2" color="text.secondary" noWrap>{account?.username}</Typography>{error&&<Typography variant="caption" color="error">{error}</Typography>}</Box><Divider/>
      <MenuItem onClick={()=>choose('profile')}><ListItemIcon><UserRound size={18}/></ListItemIcon>My profile</MenuItem>
      <MenuItem onClick={()=>choose('dashboard')}><ListItemIcon><ShieldCheck size={18}/></ListItemIcon>Staff portal</MenuItem>
      <MenuItem component="a" href="https://myaccount.microsoft.com/" target="_blank" rel="noreferrer"><ListItemIcon><ExternalLink size={18}/></ListItemIcon>Microsoft account & security</MenuItem>
      <Divider/><MenuItem disabled={busy||!configured} onClick={()=>void run(signOut)}><ListItemIcon>{busy?<CircularProgress size={18}/>:<LogOut size={18}/>}</ListItemIcon>Sign out</MenuItem>
    </Menu>
  </>;
}
