const fs = require('node:fs'); const vm = require('node:vm'); const assert = require('node:assert/strict');
function root(prefix) {
 const panels=[{id:prefix+'shifts',hidden:false},{id:prefix+'profile',hidden:true}];
 const tabs=panels.map((p,i)=>({dataset:{tab:p.id},attributes:{},classList:{toggle(){}},listeners:{},tabIndex:i? -1:0, addEventListener(k,f){this.listeners[k]=f},setAttribute(k,v){this.attributes[k]=v},focus(){this.focused=true}}));
 return {tabs,panels,querySelectorAll(s){return s==='[role="tab"]'?tabs:panels}};
}
const first=root('one-'), second=root('two-');
vm.runInNewContext(fs.readFileSync('assets/js/cockpit.js','utf8').split('jQuery(document).ready')[0],{document:{addEventListener(_,fn){fn()},querySelectorAll(){return[first,second]}}});
first.tabs[0].listeners.keydown({key:'ArrowRight',preventDefault(){}});
assert.equal(first.panels[1].hidden,false); assert.equal(first.panels[0].hidden,true); assert.equal(first.tabs[1].attributes['aria-selected'],'true'); assert.equal(first.tabs[0].tabIndex,-1); assert.equal(first.tabs[1].focused,true);
assert.equal(second.panels[1].hidden,true);
first.tabs[1].listeners.keydown({key:'Home',preventDefault(){}}); assert.equal(first.panels[0].hidden,false);
first.tabs[0].listeners.keydown({key:'ArrowLeft',preventDefault(){}}); assert.equal(first.panels[1].hidden,false);
second.tabs[1].listeners.click(); assert.equal(second.panels[1].hidden,false);
console.log('9 keyboard tab and shortcode-isolation checks passed');
