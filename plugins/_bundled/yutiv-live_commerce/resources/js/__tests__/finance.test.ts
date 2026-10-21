import { describe, it, expect } from 'vitest';
import { convertedKrw } from '../finance';
describe('manual fixed-rate preview',()=>{
    it('uses exact decimal half-up rather than binary floating point',()=>{
        expect(convertedKrw('0.03','3333.333333')).toBe(100n);
        expect(convertedKrw('0.01','50')).toBe(1n);
        expect(convertedKrw('999999999999.99','9999.999999')).toBe(9999999998999900n);
    });
    it('does not interpret blank, malformed or excessive precision as zero',()=>{
        for(const amount of ['', '1e3','-1','0.001','NaN'])expect(convertedKrw(amount,'195')).toBeNull();
        expect(convertedKrw('1','0.1234567')).toBeNull();
    });
});
