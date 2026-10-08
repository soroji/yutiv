import React from 'react';
import { Footer as FooterBasic } from '../basic/Footer';
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { H3 } from '../basic/H3';
import { P } from '../basic/P';
import { Span } from '../basic/Span';
import { shopBase, safeVerificationUrl } from '../../support/storefront';
const t = (key:string) => (window as any).G7Core?.t?.(key) ?? key;
const fields=['company','representative','business_number','mail_order_number','address','phone','email','hosting','verification_url'] as const;
export interface FooterProps {
  id?:string;siteName?:string;siteDescription?:string;copyrightText?:string;
  className?:string;editorAttrs?:Record<string,unknown>;noticeSlug?:string;
  businessInfo?:Partial<Record<typeof fields[number],string|null>>|null;
  socialLinks?:Record<string,string|undefined>;linkGroups?:{title:string;links:{label:string;href:string}[]}[];
}
const Footer:React.FC<FooterProps>=({id,siteName='YUTIV',siteDescription,copyrightText,className='',editorAttrs,noticeSlug='store-notice',businessInfo,linkGroups})=>{
  const groups=linkGroups ?? [
    {title:t('nav.shop'),links:[{label:t('home.sections.all_products'),href:shopBase()+'/products'},{label:t('stillform.story'),href:'/story'},{label:t('stillform.notice'),href:'/notice'}]},
    {title:t('footer.info'),links:[{label:t('stillform.shipping'),href:'/shipping'},{label:t('footer.terms'),href:'/page/terms'},{label:t('footer.privacy'),href:'/page/privacy'},{label:t('footer.refund'),href:'/page/refund'}]},
    {title:t('nav.mypage'),links:[{label:t('auth.login'),href:'/login'},{label:t('stillform.coupons'),href:'/mypage/coupons'},{label:t('nav.boards'),href:'/boards'},{label:t('common.search'),href:'/search'}]},
  ];
  const rows=fields.filter(key=>typeof businessInfo?.[key]==='string' && !!businessInfo[key]?.trim() && (key!=='verification_url'||!!safeVerificationUrl(businessInfo[key])));
  return <FooterBasic id={id} {...editorAttrs as Record<string,never>} className={'sf-footer '+className}>
    <Div className="sf-container"><Div className="sf-footer-grid">
      <Div><H3 className="text-2xl">{siteName}</H3>{siteDescription&&<P className="sf-intro">{siteDescription}</P>}</Div>
      {groups.map(group=><Div key={group.title} className="sf-footer-group"><H3 className="sf-footer-title">{group.title}</H3>
        {group.links.filter(link=>link.href.startsWith('/')&&!link.href.startsWith('//')&&!/[\\\u0000-\u0020]/.test(link.href)).map(link=><A key={link.href} href={link.href}>{link.label}</A>)}
      </Div>)}
    </Div>{rows.length>0&&<Div className="sf-business">{rows.map(key=><Div key={key} className="sf-business-row">
      <Span>{t('stillform.'+key)}: </Span>{key==='verification_url' ? (/^https:\/\//.test(businessInfo?.[key]??'')&&<A href={businessInfo?.[key]??''} target="_blank" rel="noopener noreferrer">{t('stillform.verification_url')}</A>):<Span>{businessInfo?.[key]}</Span>}
    </Div>)}</Div>}<P className="sf-copyright">{copyrightText || '© '+new Date().getFullYear()+' '+siteName}</P>
    <Span className="sr-only" data-notice-slug={noticeSlug}/></Div>
  </FooterBasic>;
};
export default Footer;
