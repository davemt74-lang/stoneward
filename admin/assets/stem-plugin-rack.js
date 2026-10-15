(() => {
  'use strict';
  const types=['eq','eq5','compressor','limiter','delay','reverb'];
  const display={eq:'EQ',eq5:'5-band EQ',compressor:'Compressor',limiter:'Master Limiter',
    delay:'Delay',reverb:'Reverb'};
  function render(scope,guid,list,escape){
    const esc=escape,fx=Array.isArray(list)?list:[];
    const show=fx.map((plugin,index)=>{
      const supported=!!window.SFStemFX?.specs?.[plugin.type];
      const values=window.SFStemFX?.parameters?.(plugin)||{};
      const specs=window.SFStemFX?.specs?.[plugin.type]||{};
      const source=plugin.format==='BROWSER'?'Stonefellow':
        (plugin.format||'REAPER');
      const native=plugin.format!=='BROWSER';
      const origin=plugin.parameter_provenance||'unknown';
      const original=plugin.source_enabled===null?'n/a':
        (plugin.source_enabled===false?'Bypassed in REAPER':'Enabled in REAPER');
      return '<article class="sf-plugin" data-plugin-index="'+index+'"><div class="sf-plugin-head">'+
        '<strong>'+(index+1)+'. '+esc(plugin.name||plugin.type||'Plugin')+'</strong>'+
        '<span>'+esc(source)+'</span>'+
        '<label><input type="checkbox" data-plugin-enabled '+(plugin.enabled!==false?'checked':'')+
        '> Browser enabled</label></div>'+
        '<div class="sf-plugin-provenance">'+
         (plugin.preset_name?'<span>Preset: '+esc(plugin.preset_name)+'</span>':'')+
         (plugin.fx_guid?'<span>REAPER FX GUID: '+esc(plugin.fx_guid)+'</span>':'')+
         (plugin.module?'<span>Module: '+esc(plugin.module)+'</span>':'')+
         (Number.isInteger(plugin.slot)?'<span>Original slot: '+(plugin.slot+1)+'</span>':'')+
         '<span>'+esc(original)+'</span><span>Parameter source: '+esc(origin)+'</span>'+
         (plugin.native_state_sha256?'<span>Native state SHA-256: <code>'+esc(plugin.native_state_sha256)+'</code></span>':'')+
        '</div>'+
        (plugin.js_slider_tokens?.length?'<details class="sf-plugin-js"><summary>Exact imported JSFX slider tokens</summary><code>'+
            esc(plugin.js_slider_tokens.join(' '))+'</code></details>':'')+
        '<div class="sf-plugin-controls">'+
         (supported?Object.entries(specs).map(([name,limits])=>'<label>'+esc(name)+
           '<input type="number" data-plugin-param="'+esc(name)+'" min="'+limits[0]+
           '" max="'+limits[1]+'" step="any" value="'+Number(values[name]??limits[2])+'"></label>').join(''):
           '<small>Original plugin retained; no verified browser DSP model.</small>')+
        '</div><div class="sf-plugin-actions">'+
        (supported?'<button type="button" data-plugin-action="plugin_settings">Save settings</button>':'')+
        '<button type="button" data-plugin-action="plugin_toggle">Toggle bypass</button>'+
        '<button type="button" data-plugin-action="plugin_move" data-direction="-1" '+(index===0?'disabled':'')+'>↑</button>'+
        '<button type="button" data-plugin-action="plugin_move" data-direction="1" '+(index===fx.length-1?'disabled':'')+'>↓</button>'+
        (!native?'<button type="button" data-plugin-action="plugin_remove">Remove</button>':'')+
        '</div></article>';
    }).join('');
    return '<section class="sf-plugin-rack" data-plugin-scope="'+esc(scope)+'" data-plugin-guid="'+esc(guid)+'">'+
      '<div class="sf-plugin-rack-title"><strong>'+esc(scope==='master'?'Master Bus Plugin Rack':'Track Plugin Rack')+
      '</strong><small>REAPER metadata imported exactly; browser processing is a separate approximation.</small></div>'+
      (fx.length?show:'<small>No plugins imported on this bus.</small>')+
      '<div class="sf-plugin-toolbar"><label>Add browser plugin <select data-plugin-add-type>'+
      types.map(t=>'<option value="'+t+'">'+display[t]+'</option>').join('')+
      '</select></label><button type="button" data-plugin-action="plugin_add">Add plugin</button></div>'+
      '</section>';
  }
  function bind(root,onAction){
    root.querySelectorAll('.sf-plugin-rack [data-plugin-action]').forEach(button=>{
      button.onclick=()=>{
        const rack=button.closest('.sf-plugin-rack');
        const item=button.closest('[data-plugin-index]');
        if(!rack)return;
        const payload={
          action:button.dataset.pluginAction,scope:rack.dataset.pluginScope,
          guid:rack.dataset.pluginGuid
        };
        if(item){
          payload.fx_index=Number(item.dataset.pluginIndex);
          payload.enabled=!!item.querySelector('[data-plugin-enabled]')?.checked;
        }
        if(payload.action==='plugin_toggle')payload.enabled=!payload.enabled;
        if(payload.action==='plugin_settings'){
          const settings={};
          item.querySelectorAll('[data-plugin-param]').forEach(input=>
            settings[input.dataset.pluginParam]=input.value);
          payload.settings=settings;
        }
        if(payload.action==='plugin_move')payload.direction=Number(button.dataset.direction);
        if(payload.action==='plugin_add')
          payload.type=rack.querySelector('[data-plugin-add-type]')?.value;
        onAction(payload);
      };
    });
  }
  window.SFStemPluginRack={render,bind};
})();
