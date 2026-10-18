export const travelIntentKey='wellness.travel-intent.v1';

export function captureTravelIntent() {
  const hash=new URLSearchParams(window.location.hash.slice(1));const token=hash.get('travel');
  if(window.location.pathname.endsWith('/client')&&(token||hash.get('discover')==='1')){
    if(token&&token.length<=3000&&/^[A-Za-z0-9+/=]+$/.test(token))sessionStorage.setItem(travelIntentKey,JSON.stringify({token,expires:Date.now()+1800000}));
    sessionStorage.setItem('wellness.discover-requested','1');
    window.history.replaceState(null,'',window.location.pathname+window.location.search);
  }
}
