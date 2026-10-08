/** Focus containment for the inherited JSON mobile drawer; actions remain owned by the engine. */
export function installDrawerAccessibility(): () => void {
  let active: HTMLElement | null = null;
  let previous: HTMLElement | null = null;
  const close = () => (window as any).G7Core?.dispatch?.({handler:'setState',params:{target:'global',mobileMenuOpen:false}});
  const sync = () => {
    const drawer = document.getElementById('mobile_nav_drawer');
    if (!drawer) return;
    const opened = Boolean((window as any).G7Core?.state?.get?.()?.mobileMenuOpen);
    drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');
    drawer.setAttribute('aria-label',(window as any).G7Core?.t?.('stillform.menu') ?? '');
    drawer.setAttribute('aria-hidden',String(!opened));drawer.inert = !opened;
    if (opened && !active) {
      previous=document.activeElement as HTMLElement;active=drawer;
      drawer.querySelector<HTMLElement>('button,[href],input')?.focus();
    } else if (!opened && active) {active=null;previous?.focus();previous=null;}
  };
  const onKeyDown = (event: KeyboardEvent) => {
    if (!active) return;
    if (event.key==='Escape') {event.preventDefault();close();return;}
    if (event.key!=='Tab') return;
    const elements=Array.from(active.querySelectorAll<HTMLElement>('button,[href],input,select,textarea,[tabindex="0"]')).filter(el=>!el.hasAttribute('disabled') && el.getClientRects().length>0);
    const first=elements[0],last=elements[elements.length-1];
    if (!first) {event.preventDefault();active.focus();return;}
    if(event.shiftKey && document.activeElement===first){event.preventDefault();last.focus();}
    if(!event.shiftKey && document.activeElement===last){event.preventDefault();first.focus();}
  };
  document.addEventListener('keydown', onKeyDown);
  const unsubscribe = (window as any).G7Core?.state?.subscribe?.(sync);
  const observer=new MutationObserver(sync);
  observer.observe(document.documentElement,{childList:true,subtree:true});sync();
  return () => {observer.disconnect();document.removeEventListener('keydown',onKeyDown);if(typeof unsubscribe==='function')unsubscribe();};
}
