import { describe,it,expect,beforeEach,afterEach,vi } from 'vitest';
import {render,screen,fireEvent,cleanup,act} from '@testing-library/react';
import ProductCard from '../ProductCard';
import Footer from '../Footer';
import Header from '../Header';
import {Img} from '../../basic/Img';
import {safeMediaUrl,placeholder,displayPrice,shopBase} from '../../../support/storefront';
import {formatCurrencyHandler} from '../../../handlers/formatCurrency';
import {HtmlContent} from '../HtmlContent';
import {Modal} from '../Modal';
import {Button} from '../../basic/Button';
import RichTextEditor from '../RichTextEditor';
import {IconPickerWidget} from '../../../layout-editor/IconPickerWidget';
import {installDrawerAccessibility} from '../../../support/accessibility';

let state:Record<string,any>;
let subscribers:((s:Record<string,any>)=>void)[];
let dispatch:ReturnType<typeof vi.fn>;
beforeEach(()=>{
  state={preferredCurrency:'USD',defaultCurrency:'JPY',modules:{'sirsoft-ecommerce':{basic_info:{route_path:'catalog'}}}};
  subscribers=[];dispatch=vi.fn();
  (window as any).G7Core={t:(key:string)=>key,dispatch,state:{get:()=>state,subscribe:(cb:any)=>{subscribers.push(cb);return ()=>{};}}};
});
afterEach(cleanup);
describe('final audit security regressions',()=>{
  it('blocks CSS and legacy background media even with permissive custom options',()=>{
    const {container}=render(<HtmlContent content={'<p style="background-image:url(https://outside.test/a)">Safe</p><table background="https://outside.test/b"><tbody><tr><td>Cell</td></tr></tbody></table>'} purifyConfig={{ADD_ATTR:['style','background']}}/>);
    expect(container.querySelector('[style],[background]')).toBeNull();expect(screen.getByText('Safe')).toBeVisible();
  });
  it('custom purifier options cannot enable executable attributes or URLs',()=>{
    const {container}=render(<HtmlContent content={'<p onpointerenter="alert(1)">Text</p><a href="javascript:alert(1)">Link</a>'} purifyConfig={{ADD_ATTR:['onpointerenter'],ALLOWED_URI_REGEXP:/.*/}}/>);
    expect(container.querySelector('p')?.hasAttribute('onpointerenter')).toBe(false);
    expect(container.querySelector('a')?.hasAttribute('href')).toBe(false);
  });
  it('switches from empty content to HTML to plain text without conditional hooks',()=>{
    const {rerender}=render(<HtmlContent content=""/>);
    rerender(<HtmlContent content="<p>Loaded</p>"/>);expect(screen.getByText('Loaded')).toBeVisible();
    rerender(<HtmlContent content="Plain" isHtml={false}/>);expect(screen.getByText('Plain')).toBeVisible();
  });
  it.each(['https://user:pass@example.test','https://example.test\\evil','javascript:alert(1)'])('omits malformed public verification links: %s',url=>{
    const {container}=render(<Footer businessInfo={{verification_url:url}}/>);expect(container.querySelector('.sf-business')).toBeNull();
  });
  it('rejects HTTP external media even when its origin was configured',()=>{
    state.storefront={allowedStorageOrigins:['http://storage.example.test']};expect(safeMediaUrl('http://storage.example.test/image.jpg')).toBeUndefined();
  });
  it('never inserts icon catalog preview HTML',()=>{
    const {container}=render(<IconPickerWidget control={{icons:[{value:'search',preview:{html:'<img src=x onerror="alert(1)">'}}]}} value="search" onChange={()=>{}} t={k=>k}/>);
    expect(container.querySelector('img')).toBeNull();expect(container.querySelector('[data-icon="search"]')).not.toBeNull();
  });
  it('sanitizes initial editor HTML before DOM insertion',()=>{
    const {container}=render(<RichTextEditor name="content" initialValue={'<p onpointerenter="alert(1)">Initial</p><img src="https://outside.test/image.png">'}/>);
    expect(container.querySelector('[onpointerenter]')).toBeNull();expect(container.querySelector('img')).toBeNull();
  });
});
const product={id:17,product_code:'P-CODE',thumbnail_url:'',name_localized:'A considered object',selling_price:1200,selling_price_formatted:'JPY 1,200',multi_currency_selling_price:{USD:{value:8,formatted:'USD 8.00'},EUR:{value:7,formatted:'EUR 7.00'}}};
describe('Still Form server-price and navigation contracts',()=>{
  it('uses product code and configured prefix; never ID in detail URL',()=>{
    render(<ProductCard product={product}/>);expect(screen.getByText('USD 8.00')).toBeVisible();
    fireEvent.click(screen.getByRole('button'));expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({handler:'navigate',params:expect.objectContaining({path:'/catalog/products/P-CODE'})}));
  });
  it('subscribes to preference changes and falls back to the server base formatted price',()=>{
    render(<ProductCard product={product}/>);
    act(()=>{state.preferredCurrency='EUR';subscribers.forEach(cb=>cb(state));});expect(screen.getByText('EUR 7.00')).toBeVisible();
    act(()=>{state.preferredCurrency='GBP';subscribers.forEach(cb=>cb(state));});expect(screen.getByText('JPY 1,200')).toBeVisible();
  });
  it('does not invent a detail route for a product missing its code',()=>{
    render(<ProductCard product={{...product,product_code:undefined}}/>);fireEvent.click(screen.getByRole('button'));expect(dispatch).not.toHaveBeenCalled();
  });
  it('keeps a unavailable product visible with its server reason',()=>{
    render(<ProductCard product={{...product,sales_status:'sold_out',sales_status_label:'Unavailable'}}/>);expect(screen.getByText('Unavailable')).toBeVisible();
  });
  it('sanitizes highlighted content',()=>{
    const {container}=render(<ProductCard product={{...product,name_highlighted:'<mark>Object</mark><img src=x onerror="alert(1)"><script>alert(1)</script>'}}/>);
    expect(container.querySelector('mark')).not.toBeNull();expect(container.querySelector('script')).toBeNull();expect(container.querySelector('[onerror]')).toBeNull();
  });
  it.each([true,false])('handles no_route=%s',no_route=>{state.modules['sirsoft-ecommerce'].basic_info.no_route=no_route;expect(shopBase()).toBe(no_route?'':'/catalog');});
});
describe('media allowlist and neutral fallback',()=>{
  it('enforces media origins and strips srcset inside sanitized CMS content',()=>{
    const {container}=render(<HtmlContent content={'<img src="https://unapproved.test/x" srcset="https://unapproved.test/y 2x" onerror="alert(1)"><script>alert(1)</script>'}/>);
    expect(container.querySelector('img')).toHaveAttribute('src',placeholder);expect(container.querySelector('[srcset]')).toBeNull();expect(container.querySelector('script')).toBeNull();
  });
  it.each(['javascript:alert(1)','//unapproved.test/p.png','https://unapproved.test/p.png','https://user:pass@storage.test/p.png','data:image/svg+xml,<svg/>','/\\evil.test/p'])('rejects %s',src=>expect(safeMediaUrl(src)).toBeUndefined());
  it('permits same origin and only configured storage origins',()=>{expect(safeMediaUrl('/storage/products/p.png')).toBe('/storage/products/p.png');state.storefront={allowedStorageOrigins:['https://storage.test']};expect(safeMediaUrl('https://storage.test/p.png')).toBe('https://storage.test/p.png');});
  it('replaces absent and broken images without an external request fallback',()=>{
    const {rerender}=render(<Img alt="Object"/>);expect(screen.getByRole('img')).toHaveAttribute('src',placeholder);
    rerender(<Img src="/storage/p.png" alt="Object"/>);fireEvent.error(screen.getByRole('img'));expect(screen.getByRole('img')).toHaveAttribute('src',placeholder);
  });
});
describe('modal keyboard accessibility',()=>{
  it('makes the mobile drawer inert, traps focus and closes/restores with ESC',()=>{
    const trigger=document.createElement('button');trigger.textContent='Menu';document.body.appendChild(trigger);trigger.focus();
    const drawer=document.createElement('div');drawer.id='mobile_nav_drawer';const first=document.createElement('button'),last=document.createElement('button');drawer.append(first,last);document.body.appendChild(drawer);
    for(const button of [first,last])vi.spyOn(button,'getClientRects').mockReturnValue([{}] as any);
    const dispose=installDrawerAccessibility();expect(drawer.inert).toBe(true);
    state.mobileMenuOpen=true;subscribers.forEach(cb=>cb(state));expect(drawer.inert).toBe(false);expect(document.activeElement).toBe(first);
    last.focus();fireEvent.keyDown(document,{key:'Tab'});expect(document.activeElement).toBe(first);
    fireEvent.keyDown(document,{key:'Escape'});expect(dispatch).toHaveBeenCalledWith({handler:'setState',params:{target:'global',mobileMenuOpen:false}});
    state.mobileMenuOpen=false;subscribers.forEach(cb=>cb(state));expect(document.activeElement).toBe(trigger);dispose();drawer.remove();trigger.remove();
  });
  it('contains Tab focus, supports Escape and restores the trigger',()=>{
    const trigger=document.createElement('button');document.body.appendChild(trigger);trigger.focus();const onClose=vi.fn();
    const {rerender}=render(<Modal isOpen title="Dialog" onClose={onClose}><Button>First</Button><Button>Last</Button></Modal>);
    const first=screen.getByRole('button',{name:'First'}),last=screen.getByRole('button',{name:'Last'});
    last.focus();fireEvent.keyDown(document,{key:'Tab'});expect(document.activeElement).not.toBe(last);
    first.focus();fireEvent.keyDown(document,{key:'Escape'});expect(onClose).toHaveBeenCalled();
    rerender(<Modal isOpen={false} onClose={onClose}/>);expect(document.activeElement).toBe(trigger);trigger.remove();
  });
});
describe('footer public projection',()=>{
  it.each([null,undefined,{}])('empty/failed data has no invented business rows (%s)',businessInfo=>{const {container}=render(<Footer businessInfo={businessInfo}/>);expect(container.querySelector('.sf-business')).toBeNull();});
  it('renders only populated public fields and escapes hostile values',()=>{
    const {container}=render(<Footer businessInfo={{company:'<script>bad</script>',address:'',...{api_secret:'hidden'}}}/>);
    expect(screen.getByText('<script>bad</script>')).toBeVisible();expect(container.querySelector('script')).toBeNull();expect(screen.queryByText('hidden')).toBeNull();
  });
});
describe('header preserves auth, locale and slot contracts',()=>{
  it.each([null,{uuid:'user-uuid',name:'Member'}])('supports guest/member %s',user=>{
    render(<Header user={user} siteName="YUTIV" availableLocales={['ko','en','ja','zh-CN']} currentLocale="en" shopBase="/catalog"/>);
    expect(screen.getByText('YUTIV')).toBeVisible();expect(screen.getByLabelText('common.language')).toBeVisible();
    expect(screen.getByTestId('nav-shop')).toBeVisible();expect(screen.getByText('stillform.story')).toBeVisible();
  });
  it('dispatches locale change through the engine',()=>{
    render(<Header availableLocales={['en','ja']} currentLocale="en"/>);fireEvent.click(screen.getByLabelText('common.language'));
    fireEvent.click(screen.getByText('日本語'));expect(dispatch).toHaveBeenCalledWith({handler:'setLocale',target:'ja'});
  });
});
describe('locale-aware numeric fallback',()=>{
  it.each(['ko','en','ja','zh-CN'].flatMap(locale=>['USD','JPY','EUR'].map(currency=>[locale,currency])))('%s %s',(locale,currency)=>{
    document.documentElement.lang=locale;expect(displayPrice(1234,currency)).toBe(new Intl.NumberFormat(locale,{style:'currency',currency}).format(1234));
    const context={getState:()=>currency} as any;
    expect(formatCurrencyHandler({value:1234,currencyCode:currency,locale},context)).toMatch(/1[,.]?234|1\s234/);
  });
  it('does not display nonfinite price values',()=>expect(displayPrice(NaN,'USD')).toBe(''));
});
