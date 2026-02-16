(function(){
  'use strict';

  var root=document.getElementById('app'); if(!root) return;

  var el={
    root:root.querySelector('[data-id="root"]'),
    path:root.querySelector('[data-id="path"]'),
    tbody:root.querySelector('[data-id="tbody"]'),
    chkAll:root.querySelector('[data-id="chk-all"]'),
    status:root.querySelector('[data-id="status"]'),
    progWrap:root.querySelector('[data-id="prog-wrap"]'),
    prog:root.querySelector('[data-id="prog"]'),
    summary:root.querySelector('[data-id="summary"]'),
    find:root.querySelector('[data-id="find"]'),
    repl:root.querySelector('[data-id="repl"]'),
    rx:root.querySelector('[data-id="rx"]'),
    cs:root.querySelector('[data-id="cs"]'),
    nameOnly:root.querySelector('[data-id="nameOnly"]'),
    trim:root.querySelector('[data-id="trim"]'),
    collapse:root.querySelector('[data-id="collapse"]'),
    spacesTo:root.querySelector('[data-id="spaceChar"]'),
    translit:root.querySelector('[data-id="translit"]'),
    prefix:root.querySelector('[data-id="prefix"]'),
    suffix:root.querySelector('[data-id="suffix"]'),
    useNum:root.querySelector('[data-id="useNum"]'),
    numStart:root.querySelector('[data-id="numStart"]'),
    numStep:root.querySelector('[data-id="numStep"]'),
    numPad:root.querySelector('[data-id="numPad"]'),
    numOrder:root.querySelector('[data-id="numOrder"]'),
    extNew:root.querySelector('[data-id="extNew"]')
  };

  el.root.value='/ftp/';

  var state={items:[],tmpMap:{},rulesVersion:0};
  var FS_ENDPOINT='./fs.ashx';

  function setStatus(t){ if(el.status) el.status.textContent=t; }
  function setProgress(frac){
    if(!el.prog||!el.progWrap) return;
    if(frac==null){el.progWrap.hidden=true;return;}
    el.progWrap.hidden=false; el.prog.style.width=(Math.max(0,Math.min(1,frac))*100).toFixed(1)+'%';
  }

  function qs(){var q={};try{var s=location.search.replace(/^\?/,'');if(!s)return q;s.split('&').forEach(function(p){if(!p)return;var i=p.indexOf('=');var k=decodeURIComponent(i>=0?p.slice(0,i):p);var v=decodeURIComponent(i>=0?p.slice(i+1):'');q[k]=v;});}catch(_){ }return q;}
  function normalizeDir(p){p=String(p||'/').replace(/\\/g,'/').trim();if(!p)return'/';if(!p.startsWith('/'))p='/'+p;p=p.replace(/\/+/g,'/');if(!p.endsWith('/'))p+='/';return p;}
  function extOf(name){var i=(name||'').lastIndexOf('.');return i>=0?name.slice(i+1).toLowerCase():'';}
  function nameNoExt(name){var i=name.lastIndexOf('.');return i>=0?name.slice(0,i):name;}
  function safeParseItems(s){try{var decoded=decodeURIComponent(s);var arr=JSON.parse(decoded);return Array.isArray(arr)?arr.filter(Boolean):[];}catch(_){return[];}}

  var RU='АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЫЭЮЯабвгдеёжзийклмнопрстуфхцчшщыэюяЪъЬьЙй';
  var LA=['A','B','V','G','D','E','E','Zh','Z','I','I','K','L','M','N','O','P','R','S','T','U','F','Kh','C','Ch','Sh','Sch','Y','E','Yu','Ya',
          'a','b','v','g','d','e','e','zh','z','i','i','k','l','m','n','o','p','r','s','t','u','f','kh','c','ch','sh','sch','y','e','yu','ya',
          '','','','','i','i'];
  var ruMap=(function(){var m={};for(var i=0;i<RU.length;i++){m[RU[i]]=LA[i]||'';}return m;})();
  function transliterate(s){return s.split('').map(function(ch){return ruMap[ch]!==undefined?ruMap[ch]:ch;}).join('');}

  function api(action,data){
    var fd=new FormData();
    fd.append('action',action);
    var vdir=(el.root.value||'/ftp/').replace(/\/+$/,'');
    fd.append('vdir',vdir);
    Object.keys(data||{}).forEach(function(k){ if(data[k]!=null) fd.append(k,data[k]); });
    return fetch(FS_ENDPOINT,{method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.text().then(function(txt){var js;try{js=JSON.parse(txt);}catch(_){js={ok:false,error:'Bad JSON'};}if(!r.ok||js.ok===false)throw new Error(js.error||('HTTP '+r.status));return js;});});
  }

  function listFolder(pathRel){
    return api('list',{path:pathRel}).then(function(res){
      return (res.items||[]).map(function(x){
        var name=String(x.name||'').replace(/\/$/,'');
        var e=x.dir?'':extOf(name);
        return {path:x.path,name:name,dir:!!x.dir,ext:e,checked:true,newName:'',status:''};
      });
    });
  }

  function loadItems(){
    setStatus('Загрузка…'); setProgress(null);
    var p=el.path.value.trim();
    var q=qs();
    var itemsParam=q.items?safeParseItems(q.items):null;
    if(itemsParam&&itemsParam.length){
      state.items=itemsParam.map(function(rel){
        var name=rel.split('/').filter(Boolean).pop()||''; var dir=/\/$/.test(rel);
        return {path:rel,name:name.replace(/\/$/,''),dir:dir,ext:dir?'':extOf(name),checked:true,newName:'',status:''};
      });
      setStatus('Загружено '+state.items.length+' из query'); renderTable(); return;
    }
    var pathRel=p?normalizeDir(p):'/';
    el.path.value=pathRel;
    listFolder(pathRel).then(function(items){
      state.items=items; setStatus('Загружено: '+items.length); renderTable();
    }).catch(function(err){ setStatus('Ошибка: '+(err&&err.message||err)); });
  }

  function currentRules(){
    var r={
      find:el.find.value||'',
      repl:el.repl.value||'',
      rx:!!el.rx.checked,
      cs:!!el.cs.checked,
      nameOnly:!!el.nameOnly.checked,
      trim:!!el.trim.checked,
      collapse:!!el.collapse.checked,
      spaceChar:el.spacesTo.value||' ',
      translit:!!el.translit.checked,
      prefix:el.prefix.value||'',
      suffix:el.suffix.value||'',
      useNum:!!el.useNum.checked,
      numStart:parseInt(el.numStart.value||'1',10)||1,
      numStep:parseInt(el.numStep.value||'1',10)||1,
      numPad:Math.max(0,parseInt(el.numPad.value||'2',10)||2),
      numOrder:el.numOrder.value||'name',
      extMode:(root.querySelector('input[name="extMode"]:checked')||{}).value||'keep',
      extNew:(el.extNew.value||'').replace(/^\./,'').toLowerCase()
    };
    state.rulesVersion++; return r;
  }
  function escapeRegExp(s){return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}
  function applyRulesToName(baseName,ext,idxNum,r){
    var name=r.nameOnly?baseName:(ext?baseName+'.'+ext:baseName);
    if(r.find){
      if(r.rx){var flags=r.cs?'g':'gi';try{ name=name.replace(new RegExp(r.find,flags),r.repl); }catch(_){}}else{
        if(r.cs){ name=name.split(r.find).join(r.repl); }
        else{ name=name.replace(new RegExp(escapeRegExp(r.find),'gi'),function(){return r.repl;}); }
      }
    }
    if(r.trim) name=name.trim();
    if(r.collapse) name=name.replace(/\s+/g,' ');
    if(r.spaceChar!==' ') name=name.replace(/ /g,r.spaceChar);
    if(r.translit) name=transliterate(name);
    var cmode=(root.querySelector('input[name="case"]:checked')||{}).value||'none';
    if(cmode==='lower') name=name.toLowerCase();
    else if(cmode==='upper') name=name.toUpperCase();
    else if(cmode==='title') name=name.replace(/\b([^\s]+)/g,function(w){return w.charAt(0).toUpperCase()+w.slice(1).toLowerCase();});
    if(r.useNum){
      var num=r.numStart+idxNum*r.numStep;
      var pad=String(num).padStart(r.numPad,'0');
      name=(r.prefix||'').replace(/\{n\}/g,pad)+name+(r.suffix||'').replace(/\{n\}/g,pad);
    }else{
      name=(r.prefix||'')+name+(r.suffix||'');
    }
    if(r.extMode==='set'&&!/\/$/.test(name)){
      var base=nameNoExt(name);
      if(r.extNew) name=base+'.'+r.extNew; else name=base;
    }
    return name;
  }

  function buildPreview(){
    var r=currentRules(); var items=state.items.slice();
    var orderIdx=items.map(function(it,i){return {i:i,key:r.numOrder==='name'?it.name.toLowerCase():i};})
                      .sort(function(a,b){return a.key<b.key?-1:a.key>b.key?1:0;})
                      .map(function(x){return x.i;});
    var byFolder={}; items.forEach(function(it){ var folder=it.path.replace(/[^\/]+\/?$/,''); (byFolder[folder]||(byFolder[folder]=[])).push(it); });
    var total=0,changed=0,conflicts=0;
    Object.keys(byFolder).forEach(function(folder){
      var arr=byFolder[folder];
      arr.forEach(function(it,idx){
        var base=nameNoExt(it.name),ext=it.ext;
        var idxNum=orderIdx.indexOf(state.items.indexOf(it));
        var nn=applyRulesToName(base,ext,(idxNum>=0?idxNum:idx),r);
        it.newName=nn; it.status=''; total++; if(nn!==it.name) changed++;
      });
      var seen={};
      arr.forEach(function(it){
        if(!it.checked){ it.status='skipped'; return; }
        var target=it.newName||it.name;
        if(seen[target]){ it.status='duplicate'; conflicts++; }
        seen[target]=true;
      });
    });
    el.summary.textContent='Всего: '+total+', изменится: '+changed+(conflicts?(', конфликтов: '+conflicts):'');
    renderTable();
  }

  function renderTable(){
    var frag=document.createDocumentFragment();
    state.items.forEach(function(it){
      var row=document.createElement('div'); row.className='row-item';
      var c0=document.createElement('div'); c0.className='c c0';
      var chk=document.createElement('input'); chk.type='checkbox'; chk.checked=!!it.checked;
      chk.addEventListener('change',function(){it.checked=!!chk.checked;});
      c0.appendChild(chk);
      var c1=document.createElement('div'); c1.className='c c1'; c1.textContent=it.dir?'Folder':(it.ext||'File');
      var c2=document.createElement('div'); c2.className='c c2'; c2.textContent=it.name+(it.dir?'/':'');
      var c3=document.createElement('div'); c3.className='c c3';
      var newText=it.newName?it.newName:it.name;
      c3.textContent=newText+(it.dir?'/':'');
      if(it.newName && it.newName!==it.name) c3.classList.add('ok');
      var c4=document.createElement('div'); c4.className='c c4';
      if(it.status==='duplicate'){ c4.textContent='конфликт'; c3.classList.remove('ok'); c3.classList.add('bad'); }
      else if(it.status==='skipped'){ c4.textContent='пропущен'; }
      else if(it.status==='applied'){ c4.textContent='OK'; c4.classList.add('ok'); }
      else if(it.status && it.status.indexOf('ERR:')===0){ c4.textContent=it.status; c4.classList.add('bad'); }
      else c4.textContent='';

      // Двойной клик по папке -> переход
      row.addEventListener('dblclick',function(){
        if(it.dir){
          el.path.value=normalizeDir(it.path);
          loadItems();
        }
      });

      // Одинарный клик по файлу (можно будет добавить preview или выбор позже)
      row.addEventListener('click',function(e){
        if(e.detail===1 && !it.dir){
          // просто подсветка строки – можно расширить
        }
      });

      row.appendChild(c0); row.appendChild(c1); row.appendChild(c2); row.appendChild(c3); row.appendChild(c4);
      frag.appendChild(row);
    });
    el.tbody.innerHTML=''; el.tbody.appendChild(frag);
  }

  el.chkAll && el.chkAll.addEventListener('change',function(){
    var on=!!el.chkAll.checked; state.items.forEach(function(it){it.checked=on;}); renderTable();
  });

  function applyRenames(){
    var todo=state.items.filter(function(it){
      return it.checked && !it.dir && it.newName && it.newName!==it.name && it.status!=='duplicate';
    });
    if(!todo.length){ setStatus('Нечего переименовать'); return; }

    var byFolder={}; state.items.forEach(function(it){ var folder=it.path.replace(/[^\/]+\/?$/,''); (byFolder[folder]||(byFolder[folder]=[])).push(it); });
    for(var folder in byFolder){
      var arr=byFolder[folder]; var existing={}; arr.forEach(function(it){ existing[it.name+(it.dir?'/':'')]=true; });
      var chosen=todo.filter(function(it){ return it.path.startsWith(folder); });
      for(var i=0;i<chosen.length;i++){
        var it=chosen[i]; var finalKey=it.newName+(it.dir?'/':'');
        if(existing[finalKey] && it.newName!==it.name){
          var other=arr.find(function(x){ return x.name===it.newName; });
          if(!other || !todo.includes(other)){
            it.status='ERR: target exists'; renderTable(); setStatus('Конфликт с существующим файлом'); return;
          }
        }
      }
    }

    var ts=Date.now(),seq=0;
    function tmpNameFor(it){
      var base=nameNoExt(it.name),ext=it.ext;
      var tmp='__tmp_ren_'+ts+'_'+(seq++)+'__'+base;
      return ext?(tmp+'.'+ext):tmp;
    }

    setStatus('Шаг 1/2…'); setProgress(0);
    var done=0,tmpMap={};
    function phase1(){
      if(done>=todo.length){ setStatus('Шаг 2/2…'); setProgress(0); done=0; return phase2(); }
      var it=todo[done++]; var folder=it.path.replace(/[^\/]+\/?$/,''); var dst=folder+tmpNameFor(it); tmpMap[it.path]=dst;
      api('rename',{src:it.path,dst:dst})
        .then(function(){ setProgress(done/todo.length); phase1(); })
        .catch(function(err){ it.status='ERR: '+(err&&err.message||err); renderTable(); setProgress(done/todo.length); phase1(); });
    }
    function phase2(){
      var todo2=todo.slice(); done=0;
      function step(){
        if(done>=todo2.length){
          setProgress(null); setStatus('Готово. Обнови список.');
          todo2.forEach(function(x){ x.status='applied'; }); renderTable(); return;
        }
        var it=todo2[done++]; var tmp=tmpMap[it.path]; var folder=it.path.replace(/[^\/]+\/?$/,''); var final=folder+it.newName;
        api('rename',{src:tmp,dst:final})
          .then(function(){
            it.name=it.newName; it.newName=''; it.path=final; it.ext=extOf(it.name);
            setProgress(done/todo2.length); step();
          })
          .catch(function(err){
            it.status='ERR: '+(err&&err.message||err); renderTable(); setProgress(done/todo2.length); step();
            api('rename',{src:tmp,dst:it.path}).catch(function(){});
          });
      }
      step();
    }
    phase1();
  }

  root.addEventListener('click',function(e){
    var b=e.target.closest && e.target.closest('.btn'); if(!b) return;
    var act=b.getAttribute('data-act');
    if(act==='load'){ loadItems(); }
    if(act==='preview'){ buildPreview(); }
    if(act==='apply'){ buildPreview(); applyRenames(); }
    if(act==='up'){
      var cur=normalizeDir(el.path.value||'/');
      if(cur!=='/'){
        var parts=cur.split('/').filter(Boolean); parts.pop();
        var up=parts.length?('/'+parts.join('/')+'/'):'/';
        el.path.value=up; loadItems();
      }
    }
  });

  ['input','change'].forEach(function(evt){
    root.addEventListener(evt,function(ev){
      if(!ev.target.closest('.rules')) return;
      var v=++state.rulesVersion;
      setTimeout(function(){ if(v===state.rulesVersion) buildPreview(); },120);
    });
  });

  function boot(){
    var q=qs();
    if(q.root){ el.root.value=normalizeDir(q.root); }
    if(q.path){ el.path.value=normalizeDir(q.path); }
    if(q.items){ el.path.value=''; }
    loadItems();
    setStatus('Готово.');
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',boot,{once:true}); } else { boot(); }

  /* Splitter init */
  (function initSplitter(){
    var body=root.querySelector('.br-body');
    var left=body.querySelector('.panel.left');
    var right=body.querySelector('.panel.right');
    var split=body.querySelector('.br-split');
    if(!split||!left||!right) return;
    var dragging=false,startX,startW;
    split.addEventListener('mousedown',function(e){
      dragging=true; startX=e.clientX; startW=left.getBoundingClientRect().width;
      document.body.style.cursor='col-resize'; e.preventDefault();
    });
    window.addEventListener('mousemove',function(e){
      if(!dragging) return;
      var dx=e.clientX-startX;
      var newW=Math.max(360,Math.min(startW+dx,window.innerWidth*0.7));
      left.style.setProperty('--left-w',newW+'px');
      left.style.width=newW+'px';
    });
    window.addEventListener('mouseup',function(){
      if(dragging){ dragging=false; document.body.style.cursor=''; }
    });
  })();

})();