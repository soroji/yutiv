import React from 'react';
import {describe,it,expect} from 'vitest';
import {createLayoutTest} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {ComponentRegistry} from '@core/template-engine/ComponentRegistry';
import * as basic from '../../src/components/basic';
import {Container} from '../../src/components/layout/Container';
import {Pagination} from '../../src/components/composite/Pagination';
import hero from '../../layouts/partials/home/_hero.json';
import wallet from '../../layouts/mypage/coupons.json';
import english from '../../lang/en.json';
import cartSummary from '../../layouts/partials/shop/_cart_summary.json';

function registry(){const Fragment=({children}:{children?:React.ReactNode})=><>{children}</>;const r=ComponentRegistry.getInstance();(r as any).registry=Object.fromEntries(Object.entries({...basic,Container,Pagination,Fragment}).map(([name,component])=>[name,{component,metadata:{name,type:'basic'}}]));return r;}
describe('actual DynamicRenderer layout contracts',()=>{
  it.each([{member:true,base:'/catalog'},{member:false,base:'/catalog'},{member:true,base:''},{member:false,base:''}])('cart checkout success routes member=$member base=$base',async ({member,base})=>{
    const button=(cartSummary.children as any[]).find(node=>node.actions?.[0]?.actions?.some((action:any)=>action.target==='/api/modules/sirsoft-ecommerce/checkout'));
    const api=button.actions[0].actions.find((action:any)=>action.handler==='apiCall');
    const test=createLayoutTest({components:[]} as any,{componentRegistry:registry(),initialState:{_global:{shopBase:base,currentUser:member?{uuid:'test-member'}:null}}});
    try{await test.render();await test.triggerAction(api.onSuccess[0]);expect(test.mockNavigate).toHaveBeenCalledWith(member?base+'/checkout':'/login?redirect='+encodeURIComponent(base+'/checkout'),{replace:false});}finally{test.cleanup();}
  });
  it.each([true,false])('hero resolves configured/no-route product link %s',noRoute=>{
    const test=createLayoutTest({components:[hero]} as any,{componentRegistry:registry(),translations:{stillform:english.stillform},initialState:{_global:{modules:{'sirsoft-ecommerce':{basic_info:{route_path:'catalog',no_route:noRoute}}}}}});
    return test.render().then(({container})=>{try{expect(container.querySelector('a.sf-cta')).toHaveAttribute('href',(noRoute?'':'/catalog')+'/products');expect(container.textContent).toContain(english.stillform.hero_title);}finally{test.cleanup();}});
  });
  it.each(['loaded','empty','error','loading'])('coupon wallet handles %s without fake values',async mode=>{
    const content=structuredClone(wallet.slots.content);content[0].children=content[0].children.filter((n:any)=>!n.partial);
    const test=createLayoutTest({components:content,computed:wallet.computed} as any,{componentRegistry:registry(),translations:{stillform:english.stillform,common:{loading:'Loading...',retry:'Retry'}},initialState:{_local:{couponsFailed:mode==='error'}},initialData:{coupons:{data:{coupons:{data:mode==='loaded'?[{id:7,coupon_code:'COUPON-7',status_label:'Available'}]:[],pagination:{last_page:1}}},loading:mode==='loading'},downloadable:{data:{data:[]},loading:false}}});
    try{const {container}=await test.render();if(mode==='loaded')expect(container.textContent).toContain('COUPON-7');if(mode==='error')expect(container.querySelector('[role="alert"]')).toHaveTextContent(english.stillform.coupon_error);if(mode==='loading')expect(container.querySelector('[role="status"]')).toHaveTextContent('Loading...');if(mode==='empty')expect(container.textContent).toContain(english.stillform.empty_coupons);expect(container.innerHTML).not.toContain('{{');}finally{test.cleanup();}
  });
});
