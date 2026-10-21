import {describe,it,expect} from 'vitest';
import {rate,equalShares,estimate,generatedName,initBroadcasts} from '../broadcasts';

describe('방송 수수료와 선택',()=>{
 it('정수 정밀도·균등 잔여·기존 최대 잔여 지급 순서를 유지한다',()=>{
  expect(rate('3.33')).toBe(333);expect(rate('3.333')).toBeNull();expect(rate('100.01')).toBeNull();
  expect(equalShares(['9','2','5'])).toEqual({'2':'33.34','5':'33.33','9':'33.33'});
  const v=estimate(333,525,[{id:'2',rate:3333},{id:'9',rate:6667}]);expect(v.channel).toBe(3330);expect(v.pool).toBe(5250);expect(v.total).toBe(8580);expect(v.rows.map(p=>p.amount)).toEqual([1750,3500]);
  expect(generatedName('2026-10-21T11:59')).toBe('20261021 오전방송');expect(generatedName('2026-10-21T12:00')).toBe('20261021 오후방송');
 });
});
