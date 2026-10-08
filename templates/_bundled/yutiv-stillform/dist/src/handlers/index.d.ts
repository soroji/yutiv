import { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler, removeSelectedItemHandler, updateNoOptionQuantityHandler, setBlockAdditionalOptionHandler } from './productOptions';
import { toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler } from './cartHandlers';
import { findMatchingOptionHandler, initCartOptionSelectionHandler } from './cartOptionChange';
import { initCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler, initGuestOrderTokenHandler, saveGuestOrderTokenHandler, clearGuestOrderTokenHandler, clearGuestTokenOnEntryHandler } from './storageHandlers';
import { getDisplayPriceHandler } from './getDisplayPrice';
import { formatCurrencyHandler, getCurrencySymbol } from './formatCurrency';
import { loadPreferredCurrencyHandler, savePreferredCurrencyHandler } from './loadPreferredCurrency';
import { setThemeHandler, initThemeHandler } from './setThemeHandler';
import { redirectToLoginWithReturnHandler } from './redirectToLoginWithReturn';
import { downloadAttachmentHandler } from './downloadAttachment';
/**
 * sirsoft-basic 템플릿의 모든 커스텀 핸들러
 *
 * 네이밍 규칙: 'yutiv-stillform.[핸들러명]'
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "handler": "custom",
 *   "name": "addSelectedItemIfComplete",
 *   "params": { ... }
 * }
 *
 * // 또는 풀네임으로
 * {
 *   "handler": "yutiv-stillform.addSelectedItemIfComplete",
 *   "params": { ... }
 * }
 */
export declare const handlers: {
    'yutiv-stillform.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'yutiv-stillform.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'yutiv-stillform.removeSelectedItem': typeof removeSelectedItemHandler;
    'yutiv-stillform.updateNoOptionQuantity': typeof updateNoOptionQuantityHandler;
    'yutiv-stillform.setBlockAdditionalOption': typeof setBlockAdditionalOptionHandler;
    'yutiv-stillform.getDisplayPrice': typeof getDisplayPriceHandler;
    'yutiv-stillform.formatCurrency': typeof formatCurrencyHandler;
    'yutiv-stillform.getCurrencySymbol': typeof getCurrencySymbol;
    'yutiv-stillform.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'yutiv-stillform.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    redirectToLoginWithReturn: typeof redirectToLoginWithReturnHandler;
    downloadAttachment: typeof downloadAttachmentHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
    initGuestOrderToken: typeof initGuestOrderTokenHandler;
    saveGuestOrderToken: typeof saveGuestOrderTokenHandler;
    clearGuestOrderToken: typeof clearGuestOrderTokenHandler;
    clearGuestTokenOnEntry: typeof clearGuestTokenOnEntryHandler;
};
/**
 * 핸들러 맵 (handlerMap alias)
 *
 * index.ts에서 import할 때 사용
 */
export declare const handlerMap: {
    'yutiv-stillform.addSelectedItemIfComplete': typeof addSelectedItemIfCompleteHandler;
    'yutiv-stillform.updateSelectedItemQuantity': typeof updateSelectedItemQuantityHandler;
    'yutiv-stillform.removeSelectedItem': typeof removeSelectedItemHandler;
    'yutiv-stillform.updateNoOptionQuantity': typeof updateNoOptionQuantityHandler;
    'yutiv-stillform.setBlockAdditionalOption': typeof setBlockAdditionalOptionHandler;
    'yutiv-stillform.getDisplayPrice': typeof getDisplayPriceHandler;
    'yutiv-stillform.formatCurrency': typeof formatCurrencyHandler;
    'yutiv-stillform.getCurrencySymbol': typeof getCurrencySymbol;
    'yutiv-stillform.loadPreferredCurrency': typeof loadPreferredCurrencyHandler;
    'yutiv-stillform.savePreferredCurrency': typeof savePreferredCurrencyHandler;
    setTheme: typeof setThemeHandler;
    initTheme: typeof initThemeHandler;
    redirectToLoginWithReturn: typeof redirectToLoginWithReturnHandler;
    downloadAttachment: typeof downloadAttachmentHandler;
    toggleCartItemSelection: typeof toggleCartItemSelectionHandler;
    selectAllCartItems: typeof selectAllCartItemsHandler;
    setCartOption: typeof setCartOptionHandler;
    openCartDeleteModal: typeof openCartDeleteModalHandler;
    openCartOptionModal: typeof openCartOptionModalHandler;
    recalculateCart: typeof recalculateCartHandler;
    findMatchingOption: typeof findMatchingOptionHandler;
    initCartOptionSelection: typeof initCartOptionSelectionHandler;
    initCartKey: typeof initCartKeyHandler;
    getCartKey: typeof getCartKeyHandler;
    clearCartKey: typeof clearCartKeyHandler;
    regenerateCartKey: typeof regenerateCartKeyHandler;
    saveToStorage: typeof saveToStorageHandler;
    loadFromStorage: typeof loadFromStorageHandler;
    initGuestOrderToken: typeof initGuestOrderTokenHandler;
    saveGuestOrderToken: typeof saveGuestOrderTokenHandler;
    clearGuestOrderToken: typeof clearGuestOrderTokenHandler;
    clearGuestTokenOnEntry: typeof clearGuestTokenOnEntryHandler;
};
/**
 * 핸들러 타입 정의 (TypeScript 자동완성용)
 */
export type SirsoftBasicHandlers = typeof handlers;
export { addSelectedItemIfCompleteHandler, updateSelectedItemQuantityHandler, removeSelectedItemHandler, updateNoOptionQuantityHandler, setBlockAdditionalOptionHandler, getDisplayPriceHandler, formatCurrencyHandler, getCurrencySymbol, loadPreferredCurrencyHandler, savePreferredCurrencyHandler, setThemeHandler, initThemeHandler, redirectToLoginWithReturnHandler, downloadAttachmentHandler, toggleCartItemSelectionHandler, selectAllCartItemsHandler, setCartOptionHandler, openCartDeleteModalHandler, openCartOptionModalHandler, recalculateCartHandler, findMatchingOptionHandler, initCartOptionSelectionHandler, initCartKeyHandler, getCartKeyHandler, clearCartKeyHandler, regenerateCartKeyHandler, saveToStorageHandler, loadFromStorageHandler, initGuestOrderTokenHandler, saveGuestOrderTokenHandler, clearGuestOrderTokenHandler, clearGuestTokenOnEntryHandler, };
