import React from 'react';
import { IconName, IconStyle, IconSize, iconNameMap } from './IconTypes';
export interface IconProps extends Omit<React.HTMLAttributes<HTMLElement>, 'style'> {
  name: string | IconName; iconStyle?: IconStyle; style?: React.CSSProperties;
  size?: IconSize; color?: string; spin?: boolean; pulse?: boolean; fixedWidth?: boolean; ariaLabel?: string;
}
// Original line drawings; no webfont or network dependency.
const paths: Record<string,string> = {
  search:'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
  user:'M5 21v-3a7 7 0 0 1 14 0v3M16 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
  users:'M3 21v-3a6 6 0 0 1 12 0v3M13 3a4 4 0 0 1 0 8M21 21v-3a6 6 0 0 0-4-6M12 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
  'shopping-cart':'M2 3h3l3 13h11l3-10H6M10 21h.01M18 21h.01',
  'shopping-bag':'M4 7h16v15H4zM8 8V5a4 4 0 0 1 8 0v3',
  heart:'M12 21L3 12C-4 3 7-2 12 5c5-7 16-2 9 7z',
  star:'M12 2l3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1z',
  check:'M3 12l6 6L21 5',times:'M5 5l14 14M19 5L5 19',
  plus:'M12 3v18M3 12h18',minus:'M3 12h18',
  menu:'M3 5h18M3 12h18M3 19h18',
  'chevron-down':'M5 8l7 7 7-7','chevron-up':'M5 16l7-7 7 7',
  'chevron-right':'M8 5l7 7-7 7','chevron-left':'M16 5l-7 7 7 7',
  'arrow-left':'M21 12H3l7-7M3 12l7 7','arrow-right':'M3 12h18l-7-7M21 12l-7 7',
  globe:'M2 12h20M12 2c-7 5-7 15 0 20 7-5 7-15 0-20M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  bell:'M4 17h16l-2-4V8a6 6 0 0 0-12 0v5zM9 21h6',
  moon:'M21 14A10 10 0 0 1 10 3a10 10 0 1 0 11 11',
  sun:'M12 1v3M12 20v3M1 12h3M20 12h3M4 4l2 2M18 18l2 2M4 20l2-2M18 6l2-2M17 12a5 5 0 1 1-10 0 5 5 0 0 1 10 0',
  home:'M2 11L12 2l10 9M5 9v13h14V9M9 22v-8h6v8',
  lock:'M5 10h14v12H5zM8 10V6a4 4 0 0 1 8 0v4',
  eye:'M2 12q10-16 20 0-10 16-20 0M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
  trash:'M3 5h18M8 5V2h8v3M6 5l1 17h10l1-17M10 9v9M14 9v9',
  edit:'M3 17L17 3l4 4L7 21H3zM14 6l4 4',
  envelope:'M2 4h20v16H2zM2 4l10 9L22 4',
  'map-marker-alt':'M12 22S4 14 4 9a8 8 0 0 1 16 0c0 5-8 13-8 13M15 9a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
  truck:'M2 4h12v13H2zM14 9h5l3 5v3h-8M9 19a3 3 0 1 1-6 0 3 3 0 0 1 6 0M21 19a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
  'credit-card':'M2 4h20v16H2zM2 9h20M5 15h5',
  'info-circle':'M12 10v7M12 6h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  'exclamation-triangle':'M12 2L1 22h22zM12 9v6M12 18h.01',
  'external-link-alt':'M13 3h8v8M21 3L9 15M10 3H3v18h18v-7',
  download:'M12 2v14l-6-6M12 16l6-6M3 16v6h18v-6',
  upload:'M12 16V2l-6 6M12 2l6 6M3 16v6h18v-6',
  'sign-out-alt':'M9 3H3v18h6M8 12h14l-5-5M22 12l-5 5',
  'sign-in-alt':'M15 3h6v18h-6M2 12h14l-5-5M16 12l-5 5',
  spinner:'M21 12a9 9 0 1 1-9-9',comment:'M2 3h20v14H9l-7 5z',
  box:'M3 6l9-4 9 4v14H3zM3 6l9 4 9-4M12 10v10',
  coins:'M3 6c0-5 18-5 18 0s-18 5-18 0M3 6v12c0 5 18 5 18 0V6M3 12c0 5 18 5 18 0',
  ticket:'M2 5h20v4a3 3 0 0 0 0 6v4H2v-4a3 3 0 0 0 0-6zM15 5v14',
  image:'M2 3h20v18H2zM2 17l6-6 5 5 4-4 5 5M17 7h.01',
  'file-alt':'M5 2h9l5 5v15H5zM14 2v6h5M8 12h8M8 16h8',
  fire:'M12 2s1 5-4 8c-6 4-3 12 4 12s10-8 4-13c0 0 0 4-2 5 2-6-2-12-2-12z',
  'wand-magic-sparkles':'M3 21L17 7l4 4L7 25zM4 2v6M1 5h6M18 1v4M16 3h4',
};
const aliases:Record<string,string>={close:'times',x:'times',xmark:'times',bars:'menu','star-half-stroke':'star','star-half-alt':'star','circle-notch':'spinner',refresh:'spinner',redo:'spinner',pencil:'edit','pencil-alt':'edit','pen-to-square':'edit',cart:'shopping-cart','cart-shopping':'shopping-cart',earth:'globe',language:'globe','location-dot':'map-marker-alt',warning:'exclamation-triangle','circle-info':'info-circle'};
export const Icon: React.FC<IconProps> = ({name,iconStyle: _iconStyle,size,color,spin,pulse,fixedWidth: _fixedWidth,ariaLabel,className='',style,...props}) => {
  const names=String(iconNameMap[String(name)] ?? name).split(/\s+/).filter(n=>!['fas','far','fab','fa-solid','fa-regular'].includes(n));
  const raw=(names[names.length-1]??'').replace(/^fa-/,'');
  const key=aliases[raw] ?? raw;
  const sizes:Record<string,string>={xs:'.75em',sm:'.875em',md:'1em',lg:'1.25em',xl:'1.5em','2x':'2em','3x':'3em','4x':'4em','5x':'5em'};
  return <svg {...props as React.SVGProps<SVGSVGElement>} className={'sf-icon '+(color??'')+' '+(spin||pulse?'sf-icon-spin':'')+' '+className}
    data-icon={key} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"
    aria-hidden={ariaLabel?undefined:true} aria-label={ariaLabel} role={ariaLabel?'img':undefined}
    style={{fontSize:size?sizes[size]:undefined,...style}}><path d={paths[key] ?? paths['info-circle']}/></svg>;
};
