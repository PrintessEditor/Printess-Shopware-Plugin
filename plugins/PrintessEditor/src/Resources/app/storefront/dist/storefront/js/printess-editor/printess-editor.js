(()=>{var t={150:t=>{function e(t){return Promise.resolve().then(()=>{var e=Error("Cannot find module '"+t+"'");throw e.code="MODULE_NOT_FOUND",e})}e.keys=()=>[],e.resolve=e,e.id=150,t.exports=e},857:t=>{"use strict";var e=function(t){var e;return!!t&&"object"==typeof t&&"[object RegExp]"!==(e=Object.prototype.toString.call(t))&&"[object Date]"!==e&&t.$$typeof!==i},i="function"==typeof Symbol&&Symbol.for?Symbol.for("react.element"):60103;function n(t,e){return!1!==e.clone&&e.isMergeableObject(t)?a(Array.isArray(t)?[]:{},t,e):t}function r(t,e,i){return t.concat(e).map(function(t){return n(t,i)})}function s(t){return Object.keys(t).concat(Object.getOwnPropertySymbols?Object.getOwnPropertySymbols(t).filter(function(e){return Object.propertyIsEnumerable.call(t,e)}):[])}function o(t,e){try{return e in t}catch(t){return!1}}function a(t,i,l){(l=l||{}).arrayMerge=l.arrayMerge||r,l.isMergeableObject=l.isMergeableObject||e,l.cloneUnlessOtherwiseSpecified=n;var d,u,p=Array.isArray(i);return p!==Array.isArray(t)?n(i,l):p?l.arrayMerge(t,i,l):(u={},(d=l).isMergeableObject(t)&&s(t).forEach(function(e){u[e]=n(t[e],d)}),s(i).forEach(function(e){(!o(t,e)||Object.hasOwnProperty.call(t,e)&&Object.propertyIsEnumerable.call(t,e))&&(o(t,e)&&d.isMergeableObject(i[e])?u[e]=(function(t,e){if(!e.customMerge)return a;var i=e.customMerge(t);return"function"==typeof i?i:a})(e,d)(t[e],i[e],d):u[e]=n(i[e],d))}),u)}a.all=function(t,e){if(!Array.isArray(t))throw Error("first argument should be an array");return t.reduce(function(t,i){return a(t,i,e)},{})},t.exports=a}},e={};function i(n){var r=e[n];if(void 0!==r)return r.exports;var s=e[n]={exports:{}};return t[n](s,s.exports,i),s.exports}(()=>{i.n=t=>{var e=t&&t.__esModule?()=>t.default:()=>t;return i.d(e,{a:e}),e}})(),(()=>{i.d=(t,e)=>{for(var n in e)i.o(e,n)&&!i.o(t,n)&&Object.defineProperty(t,n,{enumerable:!0,get:e[n]})}})(),(()=>{i.o=(t,e)=>Object.prototype.hasOwnProperty.call(t,e)})(),(()=>{"use strict";var t=i(857),e=i.n(t);class n{static ucFirst(t){return t.charAt(0).toUpperCase()+t.slice(1)}static lcFirst(t){return t.charAt(0).toLowerCase()+t.slice(1)}static toDashCase(t){return t.replace(/([A-Z])/g,"-$1").replace(/^-/,"").toLowerCase()}static toLowerCamelCase(t,e){let i=n.toUpperCamelCase(t,e);return n.lcFirst(i)}static toUpperCamelCase(t,e){return e?t.split(e).map(t=>n.ucFirst(t.toLowerCase())).join(""):n.ucFirst(t.toLowerCase())}static parsePrimitive(t){try{return/^\d+(.|,)\d+$/.test(t)&&(t=t.replace(",",".")),JSON.parse(t)}catch(e){return t.toString()}}}class r{static isNode(t){return"object"==typeof t&&null!==t&&(t===document||t===window||t instanceof Node)}static hasAttribute(t,e){if(!r.isNode(t))throw Error("The element must be a valid HTML Node!");return"function"==typeof t.hasAttribute&&t.hasAttribute(e)}static getAttribute(t,e){let i=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(i&&!1===r.hasAttribute(t,e))throw Error('The required property "'.concat(e,'" does not exist!'));if("function"!=typeof t.getAttribute){if(i)throw Error("This node doesn't support the getAttribute function!");return}return t.getAttribute(e)}static getDataAttribute(t,e){let i=!(arguments.length>2)||void 0===arguments[2]||arguments[2],s=e.replace(/^data(|-)/,""),o=n.toLowerCamelCase(s,"-");if(!r.isNode(t)){if(i)throw Error("The passed node is not a valid HTML Node!");return}if(void 0===t.dataset){if(i)throw Error("This node doesn't support the dataset attribute!");return}let a=t.dataset[o];if(void 0===a){if(i)throw Error('The required data attribute "'.concat(e,'" does not exist on ').concat(t,"!"));return a}return n.parsePrimitive(a)}static querySelector(t,e){let i=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(i&&!r.isNode(t))throw Error("The parent node is not a valid HTML Node!");let n=t.querySelector(e)||!1;if(i&&!1===n)throw Error('The required element "'.concat(e,'" does not exist in parent node!'));return n}static querySelectorAll(t,e){let i=!(arguments.length>2)||void 0===arguments[2]||arguments[2];if(i&&!r.isNode(t))throw Error("The parent node is not a valid HTML Node!");let n=t.querySelectorAll(e);if(0===n.length&&(n=!1),i&&!1===n)throw Error('At least one item of "'.concat(e,'" must exist in parent node!'));return n}static getFocusableElements(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document.body;return t.querySelectorAll('\n            input:not([tabindex^="-"]):not([disabled]):not([type="hidden"]),\n            select:not([tabindex^="-"]):not([disabled]),\n            textarea:not([tabindex^="-"]):not([disabled]),\n            button:not([tabindex^="-"]):not([disabled]),\n            a[href]:not([tabindex^="-"]):not([disabled]),\n            [tabindex]:not([tabindex^="-"]):not([disabled])\n        ')}static getFirstFocusableElement(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document.body;return this.getFocusableElements(t)[0]}static getLastFocusableElement(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:document,e=this.getFocusableElements(t);return e[e.length-1]}}class s{publish(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{},i=arguments.length>2&&void 0!==arguments[2]&&arguments[2],n=new CustomEvent(t,{detail:e,cancelable:i});return this.el.dispatchEvent(n),n}subscribe(t,e){let i=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{},n=this,r=t.split("."),s=i.scope?e.bind(i.scope):e;if(i.once&&!0===i.once){let e=s;s=function(i){n.unsubscribe(t),e(i)}}return this.el.addEventListener(r[0],s),this.listeners.push({splitEventName:r,opts:i,cb:s}),!0}unsubscribe(t){let e=t.split(".");return this.listeners=this.listeners.reduce((t,i)=>([...i.splitEventName].sort().toString()===e.sort().toString()?this.el.removeEventListener(i.splitEventName[0],i.cb):t.push(i),t),[]),!0}reset(){return this.listeners.forEach(t=>{this.el.removeEventListener(t.splitEventName[0],t.cb)}),this.listeners=[],!0}get el(){return this._el}set el(t){this._el=t}get listeners(){return this._listeners}set listeners(t){this._listeners=t}constructor(t=document){this._el=t,t.$emitter=this,this._listeners=[]}}class o{init(){throw Error('The "init" method for the plugin "'.concat(this._pluginName,'" is not defined.'))}update(){}_init(){this._initialized||(this.init(),this._initialized=!0)}_update(){this._initialized&&this.update()}_mergeOptions(t){let i=n.toDashCase(this._pluginName),s=r.getDataAttribute(this.el,"data-".concat(i,"-config"),!1),o=r.getAttribute(this.el,"data-".concat(i,"-options"),!1),a=[this.constructor.options,this.options,t];s&&a.push(window.PluginConfigManager.get(this._pluginName,s));try{o&&a.push(JSON.parse(o))}catch(t){throw console.error(this.el),Error('The data attribute "data-'.concat(i,'-options" could not be parsed to json: ').concat(t.message))}return e().all(a.filter(t=>t instanceof Object&&!(t instanceof Array)).map(t=>t||{}))}_registerInstance(){window.PluginManager.getPluginInstancesFromElement(this.el).set(this._pluginName,this),window.PluginManager.getPlugin(this._pluginName,!1).get("instances").push(this)}_getPluginName(t){return t||(t=this.constructor.name),t}constructor(t,e={},i=!1){if(!r.isNode(t))throw Error("There is no valid element given.");this.el=t,this.$emitter=new s(this.el),this._pluginName=this._getPluginName(i),this.options=this._mergeOptions(e),this._initialized=!1,this._registerInstance(),this._init()}}class a{stripEditorVersion(t){if(void 0!==(t=(t||"").trim())&&null!=t){if(t){for(;0==t.indexOf("/");)t=t.substring(1);for(;0<t.length&&"/"===t[t.length-1];)t=t.substring(0,t.length-1)}else t=""}return t}sanitizeHost(t){return t?(t=t.trim()).endsWith("/")?t:t+"/":t||""}getClassicEditorUrl(t,e,i){var n=(t=t||"https://editor.printess.com/").indexOf("#");if(0<n){var r=t.substring(n+1);if(r)try{var s=JSON.parse(decodeURIComponent(r));if(s)for(var o in s)s.hasOwnProperty(o)&&(i[o]=s[o])}catch(t){}t=t.substring(0,n)}return t.toLowerCase().endsWith(".html")||(t=this.sanitizeHost(t),e&&(t+=(e=this.stripEditorVersion(e))+(e?"/":"")),t+="printess-editor/embed.html"),t=0!==t.toLowerCase().indexOf("https://")&&0!==t.toLowerCase().indexOf("http://")?"https://"+t:t}getLoaderUrl(t,e,i){var n=(t=t||"https://editor.printess.com/").indexOf("#");if(0<n){var r=t.substring(n+1);if(r)try{var s=JSON.parse(decodeURIComponent(r));if(s)for(var o in s)s.hasOwnProperty(o)&&(i[o]=s[o])}catch(t){}t=t.substring(0,n)}return(t=t.toLocaleLowerCase().endsWith("embed.html")?t.substring(0,t.length-10):t).toLowerCase().endsWith(".html")||(t=this.sanitizeHost(t),e&&(t+=(e=this.stripEditorVersion(e))+(e?"/":"")),t.toLowerCase().endsWith("printess-editor/")||(t+="printess-editor/"),t+="loader.js"),t=0!==t.toLowerCase().indexOf("https://")&&0!==t.toLowerCase().indexOf("http://")?"https://"+t:t}getPrintessComponent(){return document.querySelector("printess-component")||null}applyFormFieldMappings(t,e){var i=[];if(!e)for(let e in t)t.hasOwnProperty(e)&&i.push({name:e,value:t[e]});if(t){for(let s in t)if(t.hasOwnProperty(s)){var n=t[s],r=void 0!==e[s]&&void 0!==e[s].formField?e[s].formField:s;let o=n;void 0!==e[s]&&void 0!==e[s].values&&void 0!==e[s].values[o]&&(o=e[s].values[n]),i.push({name:r,value:o})}}return i}reverseFormFieldMapping(t,e,i){let n=t||"",r=e||"";if(i){for(var s in i)if(i.hasOwnProperty(s)&&i[s].formField===t){if(i[n=s].values){for(var o in i[s].values)if(i[s].values.hasOwnProperty(o)&&i[s].values[o]===e){r=o;break}}break}}return{name:n,value:r}}setViewportMeta(){var t=document.getElementsByTagName("head");if(t&&0<t.length){let e=t[0].querySelector("meta[name=viewport]"),i=(e||((e=document.createElement("meta")).setAttribute("name","viewport"),t[0].appendChild(e)),e.getAttribute("content"));i?0>i.indexOf("interactive-widget")&&(i=i+(i?", ":"")+"interactive-widget=resizes-content",e.setAttribute("content",i)):e.setAttribute("content","interactive-widget=resizes-content")}}async initializeIFrame(t,e,i){let n=this,r=document.getElementById("printess"),s=e=>{t&&"function"==typeof t.onCloseTab&&t.onCloseTab(e)},o=e=>{switch(e.data.cmd){case"back":window.removeEventListener("message",o),window.removeEventListener("beforeunload",s),window.removeEventListener("unload",s),r.setAttribute("printessHasListener","false"),t&&"function"==typeof t.onBack&&t.onBack();break;case"basket":window.removeEventListener("message",o),window.removeEventListener("beforeunload",s),window.removeEventListener("unload",s),r.setAttribute("printessHasListener","false"),t&&"function"==typeof t.onAddToBasket&&t.onAddToBasket(e.data.token,e.data.thumbnailUrl);break;case"formFieldChanged":t&&"function"==typeof t.onFormFieldChanged&&t.onFormFieldChanged(e.data.n||e.data.name,e.data.v||e.data.value,e.data.ffCaption||"",e.data.l||e.data.label);break;case"priceChanged":t&&"function"==typeof t.onPriceChanged&&t.onPriceChanged(e.data.priceInfo);break;case"renderFirstPageImage":t&&"function"==typeof t.onRenderedFirstPageImage&&t.onRenderedFirstPageImage(e.data.result);break;case"save":t&&"function"==typeof t.onSave&&t.onSave(e.data.token)}};return new Promise(t=>{var e;r?("true"!==r.getAttribute("printessHasListener")&&(window.addEventListener("message",o),!0===i.showAlertOnTabClose&&(window.addEventListener("beforeunload",s),window.addEventListener("unload",s)),r.setAttribute("printessHasListener","true")),t(r)):((e=document.createElement("div")).setAttribute("id","printess-container"),e.setAttribute("style","display: none; position: absolute; top: 0; bottom: 0; right: 0; left: 0; width: 100%; height: 100%; z-index: 100000;"),(r=document.createElement("iframe")).setAttribute("src",this.ClassicEditorUrl),r.setAttribute("id","printess"),r.setAttribute("style","width:100%; heigth:100%;"),r.setAttribute("data-attached","false"),r.setAttribute("allow","xr-spatial-tracking; clipboard-read; clipboard-write;"),r.setAttribute("allowfullscreen","true"),e.appendChild(r),r.onload=()=>{t(r)},window.addEventListener("message",o),!0===i.showAlertOnTabClose&&(window.addEventListener("beforeunload",s),window.addEventListener("unload",s)),r.setAttribute("printessHasListener","true"),window.visualViewport&&window.visualViewport.addEventListener("scroll",()=>{var t,e,i;(i=r.contentWindow)===null||void 0===i||i.postMessage({cmd:"viewportScroll",height:(t=window.visualViewport)===null||void 0===t?void 0:t.height,offsetTop:(e=window.visualViewport)===null||void 0===e?void 0:e.offsetTop},"*")},{passive:!0}),n.setViewportMeta(),document.body.append(e))})}getPriceCategories(t){return{snippetPrices:t.snippetPrices.map(t=>t?t.label:null),priceCategories:{},price:t.formatMoney(t.getPriceForFormFields(t.getCurrentFormFieldValues())),productName:t.getProductName(),legalNotice:t.legalText,infoUrl:t.legalTextUrl}}onPriceChanged(t,e){try{let i=document.getElementById("printess"),n=null;try{t.snippetPriceCategories&&0<t.snippetPriceCategories.length?e.stickers=t.snippetPriceCategories.filter(t=>e.snippetPrices&&e.snippetPrices.length>=t.priceCategory).map(t=>({productVariantId:e.snippetPrices[t.priceCategory-1].variantId,quantity:t.amount})):e.stickers=[],n=this.calculateCurrentPrices(t,e)}catch(t){console.error(t)}i&&!e.hidePricesInEditor&&setTimeout(()=>{i.contentWindow.postMessage({cmd:"refreshPriceDisplay",priceDisplay:n},"*")},0)}catch(t){}}hideBcUiVersion(t,e){var i=this.getPrintessComponent();i&&i.editor&&i.editor.ui.hide(),"function"==typeof t.editorClosed&&t.editorClosed(!0===e)}async showBcUiVersion(t,e){let n=this;var r=t.getPriceInfo();let s=null,o=null;s=n.applyFormFieldMappings(t.getCurrentFormFieldValues(),t.getFormFieldMappings()),o=t.getMergeTemplates();var a=n.getLoaderUrl(this.Settings.editorUrl,this.Settings.editorVersion,{}),a=await i(150)(a);let l=n.getPrintessComponent();l&&l.editor?(l.style.display="block",await l.editor.api.loadTemplateAndFormFields(t.templateNameOrSaveToken,o,s,null),l.editor.ui.show()):(r={mergeTemplates:o,formFields:s,token:n.Settings.shopToken,templateName:t.templateNameOrSaveToken,translationKey:"",basketId:"function"==typeof t.getBasketId&&t.getBasketId()||"Some-Unique-Basket-Or-Session-Id",shopUserId:"function"==typeof t.getUserId?t.getUserId()||"Some-Unique-Basket-Or-Session-Id":"Some-Unique-Shop-User-Id",snippetPriceCategoryLabels:r&&r.snippetPrices?r.snippetPrices:null,theme:n.Settings.uiSettings&&n.Settings.uiSettings.theme||"",addToBasketCallback:(t,i)=>{e&&"function"==typeof e.onAddToBasket&&e.onAddToBasket(t,i)},formFieldChangedCallback:(t,i,n,r,s)=>{e&&"function"==typeof e.onFormFieldChanged&&e.onFormFieldChanged(t,i,s,r)},priceChangeCallback:t=>{e&&"function"==typeof e.onPriceChanged&&e.onPriceChanged(t)},backButtonCallback:e=>{n.hideBcUiVersion(t,!0)},saveTemplateCallback:(t,i)=>{"function"==typeof e.onSave&&e.onSave(t)}},a=await a.load(r),(l=n.getPrintessComponent())&&(l.editor=a))}async show(t){let e=this,i=!1;t&&t.templateNameOrSaveToken&&(i=0===t.templateNameOrSaveToken.indexOf("st:")&&90===t.templateNameOrSaveToken.length);var n={onBack:()=>{e.hide(t,!0)},onAddToBasket:(i,n)=>{let r=t.onAddToBasket(i,n);r&&r.waitUntilClosingMS?setTimeout(function(){"function"==typeof r.executeBeforeClosing&&r.executeBeforeClosing(),e.hide(t,!1)},r.waitUntilClosingMS):(r&&"function"==typeof r.executeBeforeClosing&&r.executeBeforeClosing(),e.hide(t,!1))},onFormFieldChanged:(i,n,r,s)=>{i=e.reverseFormFieldMapping(i,n,t.getFormFieldMappings()),"function"==typeof t.onFormFieldChanged&&t.onFormFieldChanged(i.name,i.value,r,s)},onPriceChanged:i=>{e.onPriceChanged(i,t)},onRenderedFirstPageImage:e=>{"function"==typeof t.onRenderFirstPageImage&&t.onRenderFirstPageImage(e)},onSave:e=>{"function"==typeof t.onSave&&t.onSave(e,"")},onCloseTab:t=>{t.preventDefault(),t.returnValue=""}};if(this.Settings.uiSettings&&"bcui"===this.Settings.uiSettings.uiVersion)e.showBcUiVersion(t,n);else{var r=t.getPriceInfo();let e=null,o=null;if(i||(e=this.applyFormFieldMappings(t.getCurrentFormFieldValues(),t.getFormFieldMappings()),o=t.getMergeTemplates()),"false"===(n=await this.initializeIFrame(n,t,this.Settings)).getAttribute("data-attached"))try{var s={token:this.Settings.shopToken||"",templateName:t.templateNameOrSaveToken,showBuyerSide:!0,templateUserId:"",basketId:"function"==typeof t.getBasketId&&t.getBasketId()||"Some-Unique-Basket-Or-Session-Id",shopUserId:"function"==typeof t.getUserId?t.getUserId()||"Some-Unique-Basket-Or-Session-Id":"Some-Unique-Shop-User-Id",formFields:e,snippetPriceCategoryLabels:r&&r.snippetPrices?r.snippetPrices:null,mergeTemplates:o};if(void 0!==t.showSplitterGridSizeButton&&null!==t.showSplitterGridSizeButton&&(s.showSplitterGridSizeButton=!0===t.showSplitterGridSizeButton||"true"===t.showSplitterGridSizeButton),this.Settings.uiSettings&&this.Settings.uiSettings.theme&&(s.theme=this.Settings.uiSettings.theme),this.Settings.attachParams)for(let t in this.Settings.attachParams)this.Settings.attachParams.hasOwnProperty(t)&&(s[t]=this.Settings.attachParams[t]);if(void 0!==t.additionalAttachParams||null!==t.additionalAttachParams)for(let e in t.additionalAttachParams)t.additionalAttachParams.hasOwnProperty(e)&&(s[e]=t.additionalAttachParams[e]);n.contentWindow.postMessage({cmd:"attach",properties:s},"*"),n.setAttribute("data-attached","true")}catch(t){console.error(t)}else n.contentWindow.postMessage({cmd:"loadTemplateAndFormFields",parameters:[t.templateNameOrSaveToken,o,e,void 0]},"*")}document.body.classList.add("hideAll"),(r=document.getElementsByTagName("html"))&&0<r.length&&r[0].classList.add("printessEditorOpen"),(n=document.getElementById("printess-container"))&&n.style.setProperty("display","block","important")}hide(t,e){this.Settings.uiSettings&&"bcui"===this.Settings.uiSettings.uiVersion?(i=this.getPrintessComponent())&&i.editor&&i.editor.ui.hide():(i=document.getElementById("printess-container"))&&(i.style.display="none"),document.body.classList.remove("hideAll");var i=document.getElementsByTagName("html");i&&0<i.length&&i[0].classList.remove("printessEditorOpen"),"function"==typeof t.editorClosed&&t.editorClosed(!0===e)}constructor(t){if(this.calculateCurrentPrices=(t,e)=>{var i=this.getPriceCategories(e);let n=e.getPriceForFormFields(e.getCurrentFormFieldValues());return t.snippetPriceCategories&&t.snippetPriceCategories.forEach(t=>{t&&0<t.amount&&e.snippetPrices[t.priceCategory-1]&&(n+=e.snippetPrices[t.priceCategory-1].priceInCent)}),i.price=e.formatMoney(n),i},!t||!t.shopToken)throw"No shop token provided";this.Settings={...t},t={},void 0!==this.Settings.uiSettings&&null!==this.Settings.uiSettings&&(t.showAnimation=!0===this.Settings.uiSettings.showStartupAnimation||"true"==this.Settings.uiSettings.showStartupAnimation,this.Settings.uiSettings.startupLogoUrl&&(t.imageUrl=this.Settings.uiSettings.startupLogoUrl),this.Settings.uiSettings.startupBackgroundColor)&&(t.background=this.Settings.uiSettings.startupBackgroundColor),this.ClassicEditorUrl=this.getClassicEditorUrl(this.Settings.editorUrl,this.Settings.editorVersion,t)+"#"+encodeURIComponent(JSON.stringify(t))}}class l{getProductOptionInputSelector(t){return"input[name='"+t+"'], select[name='"+t+"']"}getVariantInputs(){let t=[];return this.recordForeach(this.product.variantOptions,(e,i)=>{document.querySelectorAll(this.getProductOptionInputSelector(e)).forEach(e=>{t.push(e)})}),t}getCurrentProductOptionsFromProductPage(){var t=this.getVariantInputs();let e={},i={};return t.forEach(t=>{var n,r=t.getAttribute("name");this.product.variantOptions.hasOwnProperty(r)&&("SELECT"===t.nodeName?(n=t.value,this.product.variantOptions[r].values.hasOwnProperty(n)&&(e[this.product.variantOptions[r].name]=this.product.variantOptions[r].values[n].name,i[r]=!0)):"radio"===t.getAttribute("type")&&(n=t.value,t.checked)&&this.product.variantOptions[r].values.hasOwnProperty(n)&&(e[this.product.variantOptions[r].name]=this.product.variantOptions[r].values[n].name,i[r]=!0))}),e}getCurrentProductOptions(t){return"cart"!==t.displayMode?this.getCurrentProductOptionsFromProductPage():t.basketItemOptions}recordForeach(t,e){let i=-1;for(let n in t)if(t.hasOwnProperty(n)&&!1===e(n,t[n],++i))break}filterVariantRelatedOptions(t){var e={};for(let r in t)if(t.hasOwnProperty(r)){var i=t[r];for(let t in this.product.variantOptions)if(this.product.variantOptions.hasOwnProperty(t)){var n=this.product.variantOptions[t];if(n.name===r)for(let t in n.values)n.values.hasOwnProperty(t)&&n.values[t].name===i&&(e[r]=i)}}return e}getVariantByProductOptions(t){let e=this.filterVariantRelatedOptions(t),i=this.product.variants;for(let t in e)e.hasOwnProperty(t)&&(i=i.filter(i=>i.options&&i.options.hasOwnProperty(t)&&i.options[t].optionName===e[t]));return 0<i.length?i[0]:this.product}addOrRemoveTextField(t,e,i,n,r){let s=arguments.length>5&&void 0!==arguments[5]?arguments[5]:null;if(s=s||this.settings.designNowButtonInstance.closest("form"),Array.isArray(n))n.forEach(n=>{this.addOrRemoveTextField(t,e,i,n,r,s)});else{let t=document.getElementById(n);r?(!t&&s&&((t=document.createElement("input")).setAttribute("id",n),t.setAttribute("name","lineItems["+e+"]["+i+"]"),t.setAttribute("type","hidden"),t.style.display="none",s.appendChild(t)),t&&t.setAttribute("value",r)):t&&t.remove()}}createShopContext(t){let e=this;var i,n,r={templateNameOrSaveToken:"",stickers:[],legalText:this.product.metaData.hasOwnProperty("PrintessLegalText")?this.product.metaData.PrintessLegalText||"":t.priceFormatOptions&&t.priceFormatOptions.legalText?t.priceFormatOptions.legalText:"",legalTextUrl:this.product.metaData.hasOwnProperty("PrintessLegalTextUrl")&&this.product.metaData.PrintessLegalTextUrl||"",snippetPrices:[],chargeEachStickerUsage:!1,hidePricesInEditor:void 0!==e.settings.hidePricesInEditor&&!0===e.settings.hidePricesInEditor,getProductName:()=>e.product.name,formatMoney:e=>{var i,n;let r=parseFloat(""+e).toFixed(2);return t.priceFormatOptions&&(i={minimumFractionDigits:2,maximumFractionDigits:20},t.priceFormatOptions.rounding&&(i.minimumFractionDigits=t.priceFormatOptions.rounding.decimal,i.maximumFractionDigits=t.priceFormatOptions.rounding.decimal),i={style:"currency",currency:t.priceFormatOptions.currencyIsoCode,...i},r=e.toLocaleString((n=function(){var t,e,i;if("object"==typeof navigator)return t="anguage",(i=(e=navigator).languages)&&i.length?i:(t=e["l"+t]||e["browserL"+t]||e["userL"+t])&&[t]}()[0])!==null&&void 0!==n?n:"en-US",i)),r},getMergeTemplates:()=>{var t=[];if(this.product.metaData.hasOwnProperty("PrintessMergeTemplates")&&this.product.metaData.PrintessMergeTemplates)try{var e=JSON.parse(this.product.metaData.PrintessMergeTemplates);e&&t.push("string"==typeof e?{templateName:e||""}:e)}catch(e){t.push({templateName:this.product.metaData.hasOwnProperty("PrintessMergeTemplates")&&this.product.metaData.PrintessMergeTemplates||""})}return t},onFormFieldChanged:(e,i,n,r)=>{let s=this.getVariantByProductOptions(this.getCurrentProductOptions(t));if(t.basketItemOptions||(t.basketItemOptions={}),t.basketItemOptions[e]=i,"cart"!==t.displayMode){let a=null,l=null;this.recordForeach(this.product.variantOptions,(t,s)=>{if(s.name===e||s.name===n){for(let t in s.values)if(s.values.hasOwnProperty(t)){var o=s.values[t];if(i===o.name||o.name===r)return a=t,l=s.id,!1}}}),null!=a&&null!=l&&document.querySelectorAll(this.getProductOptionInputSelector(l)).forEach(t=>{var e=t.getAttribute("name");if(this.product.variantOptions.hasOwnProperty(e)){if("SELECT"===t.nodeName){t.value=a;for(let e=0;e<t.options.length;++e)t.options[e].selected=t.options[e].value===a}else"radio"===t.getAttribute("type")&&(t.checked=t.value===a)}});var o=document.querySelectorAll("[name^='lineItems\\["+s.id+"]'");let d="lineItems["+s.id+"]";s=this.getVariantByProductOptions(this.getCurrentProductOptions(t)),o.forEach(t=>{t.getAttribute("name")===d+"[id]"&&(t.value=s.id),t.setAttribute("name",t.getAttribute("name").replace(d,"lineItems["+s.id+"]"))})}},onAddToBasket:(i,n)=>{if("cart"===t.displayMode){var r,s,o=document.getElementById("printessupdateLineItem"+t.lineItemId);o&&(a=o.querySelector("input[name='lineItems\\[id\\]'],input[name='lineItems\\[0\\]\\[id\\]']"),l=o.querySelector("input[name='lineItems\\[quantity\\]'],input[name='lineItems\\[0\\]\\[quantity\\]']"),r=o.querySelector("input[name='lineItems\\[referencedId\\]'],input[name='lineItems\\[0\\]\\[referencedId\\]']"),s=o.querySelector("input[name='lineItems\\[payload\\]'],input[name='lineItems\\[0\\]\\[payload\\]']"),a&&(a.value=t.lineItemId),l&&(l.value=t.lineItem.quantity.toString()),r&&(r.value=t.lineItem.referencedId),s&&(s.value=JSON.stringify({_printessProductOptions:t.basketItemOptions,_printessSaveToken:i,_printessThumbnailUrl:n})),o.submit())}else{let r=this.getVariantByProductOptions(e.getCurrentProductOptions(t));var a=this.settings.designNowButtonInstance.closest("form"),l=(console.log(r),this.addOrRemoveTextField(t,r.id,"_printessSaveToken","printess_save_token_input_"+r.id,i,a),this.addOrRemoveTextField(t,r.id,"_printessThumbnailUrl","printess_thumbnail_url_input_"+r.id,n,a),this.addOrRemoveTextField(t,r.id,"_printessProductOptions","printess_product_options_"+r.id,JSON.stringify({...t.basketItemOptions||{},...this.getCurrentProductOptionsFromProductPage()}),a),document.getElementsByName("lineItems["+r.id+"][stackable]"));l&&l.forEach(t=>t.value="0");let s=document.getElementsByName("lineItems["+r.id+"][id]");s&&s.forEach(t=>t.value=""+r.id),(s=document.getElementsByName("lineItems["+r.id+"][referencedId]"))&&s.forEach(t=>t.value=""+r.id),this.settings.addToBasketButtonInstance&&this.settings.addToBasketButtonInstance.click()}},getCurrentFormFieldValues:()=>e.getCurrentProductOptions(t),getPriceForFormFields:i=>{var n=e.getVariantByProductOptions(e.getCurrentProductOptions(t)),r=n.price[0].gross;return t.priceFormatOptions&&!t.priceFormatOptions.grossPrice&&n.price[0].net,r},getFormFieldMappings:()=>{let t={};if(this.product.metaData.hasOwnProperty("PrintessFormFieldMappings")&&this.product.metaData.PrintessFormFieldMappings)try{t=JSON.parse(this.product.metaData.PrintessFormFieldMappings)}catch(t){console.error(t)}return t},getPriceInfo:()=>null,editorClosed:t=>{}};return t.saveToken?r.templateNameOrSaveToken=t.saveToken:(i=this.getCurrentProductOptions(t),n=this.getVariantByProductOptions(i),r.templateNameOrSaveToken=this.doDisplayDesignNowButton(i)?n.metaData.PrintessTemplateName||this.product.metaData.PrintessTemplateName:this.product.metaData.PrintessTemplateName||""),r}doDisplayDesignNowButton(t){let e=!1;return this.product.variants.forEach(t=>{e=e||t.metaData.hasOwnProperty("PrintessTemplateName")&&t.metaData.PrintessTemplateName&&!0}),t=this.getVariantByProductOptions(t),e?t.metaData.hasOwnProperty("PrintessTemplateName")&&t.metaData.PrintessTemplateName&&!0:this.product.metaData.hasOwnProperty("PrintessTemplateName")&&this.product.metaData.PrintessTemplateName&&!0}showDesignNowButton(t){this.settings.designNowButtonInstance&&this.settings.addToBasketButtonInstance&&(this.settings.addToBasketButtonInstance.getAttribute("data-orig-display")||this.settings.addToBasketButtonInstance.setAttribute("data-orig-display",this.settings.addToBasketButtonInstance.style.display),this.settings.addToBasketButtonInstance.style.display=t?"none":this.settings.addToBasketButtonInstance.getAttribute("data-orig-display"),this.settings.designNowButtonInstance.style.display=t?"inline-block":"none")}initProductPage(){this.showDesignNowButton(this.doDisplayDesignNowButton(this.getCurrentProductOptionsFromProductPage()))}show(t){"cart"!==t.displayMode&&(e=this.settings.designNowButtonInstance.closest("form"))&&(e=e.querySelector("input[name^='lineItems\\['][name$='\\]\\[id\\]']"))&&(e=e.getAttribute("name").replace("lineItems[","").replace("][id]",""),t.lineItemId=e);var e=this.createShopContext(t);new a(this.settings).show(e)}constructor(t,e){this.name=0,this.product=e,(this.settings=t)&&!t.uiSettings&&(t.uiSettings={showStartupAnimation:"boolean"==typeof t.showStartupAnimation&&t.showStartupAnimation,theme:t.theme||null,uiVersion:"classical",startupBackgroundColor:null,startupLogoUrl:t.startupLogoUrl||null}),this.name=Math.random(),console.log(this.name)}}class d{get(t,e){let i=arguments.length>2&&void 0!==arguments[2]?arguments[2]:"application/json",n=this._createPreparedRequest("GET",t,i);return this._sendRequest(n,null,e)}post(t,e,i){let n=arguments.length>3&&void 0!==arguments[3]?arguments[3]:"application/json";n=this._getContentType(e,n);let r=this._createPreparedRequest("POST",t,n);return this._sendRequest(r,e,i)}delete(t,e,i){let n=arguments.length>3&&void 0!==arguments[3]?arguments[3]:"application/json";n=this._getContentType(e,n);let r=this._createPreparedRequest("DELETE",t,n);return this._sendRequest(r,e,i)}patch(t,e,i){let n=arguments.length>3&&void 0!==arguments[3]?arguments[3]:"application/json";n=this._getContentType(e,n);let r=this._createPreparedRequest("PATCH",t,n);return this._sendRequest(r,e,i)}abort(){if(this._request)return this._request.abort()}setErrorHandlingInternal(t){this._errorHandlingInternal=t}_registerOnLoaded(t,e){e&&(!0===this._errorHandlingInternal?(t.addEventListener("load",()=>{e(t.responseText,t)}),t.addEventListener("abort",()=>{console.warn("the request to ".concat(t.responseURL," was aborted"))}),t.addEventListener("error",()=>{console.warn("the request to ".concat(t.responseURL," failed with status ").concat(t.status))}),t.addEventListener("timeout",()=>{console.warn("the request to ".concat(t.responseURL," timed out"))})):t.addEventListener("loadend",()=>{e(t.responseText,t)}))}_sendRequest(t,e,i){return this._registerOnLoaded(t,i),t.send(e),t}_getContentType(t,e){return t instanceof FormData&&(e=!1),e}_createPreparedRequest(t,e,i){return this._request=new XMLHttpRequest,this._request.open(t,e),this._request.setRequestHeader("X-Requested-With","XMLHttpRequest"),i&&this._request.setRequestHeader("Content-type",i),this._request}constructor(){this._request=null,this._errorHandlingInternal=!1}}let u=window.PluginManager;u.register("PrintessEditor",class extends o{init(){this.BUY_BTN_SELECTOR="button.btn.btn-primary.btn-buy",this.printessEditor=null,this.info=null;let t=this.el.closest("form"),e=null;t&&(e=t.querySelector(this.BUY_BTN_SELECTOR))&&e.parentNode.insertBefore(this.el,e.nextSibling);let i=this.el.getAttribute("data-product-id");if(i&&"function"==typeof window["getPrintessInfo"+i]){let t=this.info=window["getPrintessInfo"+i]();t.product.variants=t.variants,delete t.variants,t.settings.designNowButtonInstance=this.el,t.settings.addToBasketButtonInstance=e,this.printessEditor=new l(t.settings,t.product),this.printessEditor.initProductPage()}this.el.addEventListener("click",this.editProductClicked.bind(this))}editProductClicked(){this.printessEditor&&this.printessEditor.show({priceFormatOptions:this.info.priceSettings})}},"[data-printess-button]"),u.register("PrintessCart",class extends o{init(){this.infos=null,this.lineItemId=this.el.getAttribute("data-printess-line-item-id"),this.lineItem=JSON.parse(this.el.getAttribute("data-printess-line-item"));try{let t=JSON.parse(this.el.getAttribute("data-printess-product-infos"));t&&(this.infos=t)}catch(t){console.error(t)}this.el.addEventListener("click",this.editProductClicked.bind(this))}editProductClicked(){if(this.infos.hasOwnProperty(this.lineItemId)){let t=this.infos[this.lineItemId];t&&t.content&&"string"==typeof t.content&&(t=JSON.parse(t.content)),t.product.variants=t.variants,delete t.variants;let e={lineItemId:this.lineItemId,displayMode:"cart",basketItemOptions:this.lineItem&&this.lineItem.payload&&this.lineItem.payload._printessProductOptions?"string"==typeof this.lineItem.payload._printessProductOptions?JSON.parse(this.lineItem.payload._printessProductOptions):this.lineItem.payload._printessProductOptions:{},saveToken:this.lineItem&&this.lineItem.payload&&this.lineItem.payload._printessSaveToken?this.lineItem.payload._printessSaveToken:"",updateBasketItem:this.updateCartItem,lineItem:this.lineItem,priceFormatOptions:t.priceSettings};new l(t.settings,t.product).show(e)}else console.error("Can not open editor")}updateCartItem(t){xhr=new d().post("/store-api/checkout/cart/line-item",JSON.stringify({items:[t]}),t=>{alert(JSON.stringify(t))})}},"[data-printess-line-item-id]")})()})();