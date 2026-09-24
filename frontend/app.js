
(function initTheme(){
  let t;
  try{ t=localStorage.getItem('ptc_theme'); }catch(e){}
  if(t==='dark') document.documentElement.setAttribute('data-theme','dark');
})();
function toggleTheme(){
  const html=document.documentElement;
  html.classList.add('theme-anim');
  const dark=html.getAttribute('data-theme')==='dark';
  if(dark) html.removeAttribute('data-theme');
  else html.setAttribute('data-theme','dark');
  try{ localStorage.setItem('ptc_theme', dark?'light':'dark'); }catch(e){}
  setTimeout(()=>html.classList.remove('theme-anim'),450);
}


const PTCUtils = (function(){
  function escapeHTML(value){
    return String(value ?? '').replace(/[&<>'"]/g,ch=>({
      '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'
    })[ch]);
  }

  function safeUrl(value,{allowDataImage=false,allowMailto=false}={}){
    const raw=String(value||'').trim();
    if(!raw) return null;
    if(allowDataImage && /^data:image\/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$/i.test(raw)) return raw;
    try{
      const u=new URL(raw,location.href);
      if(u.protocol==='http:'||u.protocol==='https:'||(allowMailto&&u.protocol==='mailto:')) return u.href;
    }catch(e){}
    return null;
  }

  
  
  
  function safeRef(value){
    const raw=String(value ?? '').trim();
    return /^[A-Za-z0-9_-]{1,80}$/.test(raw) ? raw : null;
  }

  
  
  function safePage(value,fallback='index.html'){
    const raw=String(value ?? '').trim();
    return /^[A-Za-z0-9_-]{1,60}\.html$/.test(raw) ? raw : fallback;
  }

  function sanitizeStyle(value){
    const allowed=new Set(['font-weight','font-style','text-decoration','background-color','color','text-align']);
    return String(value||'').split(';').map(part=>part.trim()).filter(Boolean).map(part=>{
      const i=part.indexOf(':'); if(i<1) return '';
      const key=part.slice(0,i).trim().toLowerCase();
      const val=part.slice(i+1).trim();
      if(!allowed.has(key)||/url\s*\(|expression\s*\(|javascript:/i.test(val)) return '';
      return `${key}:${val}`;
    }).filter(Boolean).join(';');
  }

  function sanitizeHTML(html){
    const template=document.createElement('template');
    template.innerHTML=String(html||'');
    const allowed=new Set(['B','STRONG','I','EM','U','P','DIV','BR','H1','H2','H3','UL','OL','LI','BLOCKQUOTE','SPAN','MARK','IMG','A']);
    const nodes=[...template.content.querySelectorAll('*')];
    nodes.forEach(el=>{
      const attrs=Object.fromEntries([...el.attributes].map(a=>[a.name.toLowerCase(),a.value]));
      if(!allowed.has(el.tagName)){
        if(['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','META','LINK','BASE'].includes(el.tagName)) el.remove();
        else el.replaceWith(...el.childNodes);
        return;
      }
      [...el.attributes].forEach(a=>el.removeAttribute(a.name));
      if(attrs.dir && /^(rtl|ltr|auto)$/i.test(attrs.dir)) el.setAttribute('dir',attrs.dir);
      const style=sanitizeStyle(attrs.style); if(style) el.setAttribute('style',style);
      if(el.tagName==='IMG'){
        const srcUrl=safeUrl(attrs.src,{allowDataImage:true});
        if(!srcUrl){ el.remove(); return; }
        el.setAttribute('src',srcUrl); el.setAttribute('alt',attrs.alt||'صورة مرفقة');
      }
      if(el.tagName==='A'){
        const href=safeUrl(attrs.href,{allowMailto:true});
        if(!href){ el.replaceWith(...el.childNodes); return; }
        el.setAttribute('href',href); el.setAttribute('target','_blank'); el.setAttribute('rel','noopener noreferrer');
      }
    });
    return template.innerHTML;
  }

  function setIconLabel(el,icon,text){
    if(!el) return;
    el.replaceChildren();

    
    if(icon && typeof ic==='function'){
      const holder=document.createElement('span');
      holder.innerHTML=ic(icon);

      if(holder.firstElementChild){
        el.appendChild(holder.firstElementChild);
        el.appendChild(document.createTextNode(' '));
      }
    }

    el.appendChild(document.createTextNode(String(text||'')));
  }

  
  
  
  
  
  
  function copyable(value,options){
    const opts=options||{};
    const raw=String(value ?? '').trim();
    if(!raw) return '<span class="copyable-empty">—</span>';

    const safeValue=escapeHTML(raw);
    const safeDir=opts.dir==='rtl'?'rtl':'ltr';
    const safeClass=opts.mono===false?'copyable-text':'copyable-text mono';
    const safeIcon=typeof ic==='function'?ic('clipboard',14):'⧉';

    return `<span class="copyable" dir="${safeDir}"><span class="${safeClass}">${safeValue}</span><button type="button" class="copy-btn" data-copy="${safeValue}" title="نسخ" aria-label="نسخ ${safeValue}">${safeIcon}</button></span>`;
  }

  
  function arabicCount(value,forms){
    const n=Number(value)||0;

    if(n===0&&forms.zero) return forms.zero;
    if(n===1) return forms.one;
    if(n===2) return forms.two;
    if(n>=3&&n<=10) return n+' '+forms.few;

    return n+' '+forms.many;
  }

  const COUNT_HOURS={one:'ساعة واحدة',two:'ساعتان',few:'ساعات',many:'ساعة',zero:'بلا ساعات'};
  const COUNT_COURSES={one:'مادة واحدة',two:'مادتان',few:'مواد',many:'مادة',zero:'لا مواد'};

  const hoursCount=value=>arabicCount(value,COUNT_HOURS);
  const coursesCount=value=>arabicCount(value,COUNT_COURSES);

  return {escapeHTML,safeUrl,safeRef,safePage,sanitizeHTML,setIconLabel,copyable,
    arabicCount,hoursCount,coursesCount};
})();



(function initCopyButtons(){
  
  function fallbackCopy(text){
    const area=document.createElement('textarea');
    area.value=text;
    area.setAttribute('readonly','');
    area.style.position='fixed';
    area.style.insetInlineStart='-9999px';
    document.body.appendChild(area);
    area.select();

    let done=false;
    try{ done=document.execCommand('copy'); }catch(e){ done=false; }

    area.remove();
    return done;
  }

  
  function flash(button,ok){
    if(button.dataset.copyBusy) return;
    button.dataset.copyBusy='1';

    const original=button.innerHTML;
    const mark=typeof ic==='function'?ic(ok?'check':'alert',14):(ok?'✓':'✕');

    button.innerHTML=mark;
    button.classList.add(ok?'copied':'copy-failed');

    setTimeout(function(){
      button.innerHTML=original;
      button.classList.remove('copied','copy-failed');
      delete button.dataset.copyBusy;
    },1400);
  }

  document.addEventListener('click',function(event){
    const button=event.target.closest('.copy-btn');
    if(!button) return;

    
    event.preventDefault();
    event.stopPropagation();

    const text=button.dataset.copy||'';
    if(!text) return;

    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(
        function(){ flash(button,true); },
        function(){ flash(button,fallbackCopy(text)); }
      );
      return;
    }

    flash(button,fallbackCopy(text));
  });
})();










const UIBackStack = (function(){
  const stack = [];
  let ignoreNextPop = false;

  window.addEventListener('popstate', () => {
    if (ignoreNextPop) { ignoreNextPop = false; return; }
    const top = stack.pop();
    if (top) top.close();
  });

  return {
    
    
    push(id, closeFn){
      stack.push({ id, close: closeFn });
      history.pushState({ uiBack: id }, '');
    },
    
    
    
    pop(id){
      const idx = stack.findIndex(s => s.id === id);
      if (idx === -1) return; 
      const isTop = idx === stack.length - 1;
      stack.splice(idx, 1);
      
      if (isTop){
        ignoreNextPop = true;
        history.back();
      }
    },
    
    
    
    
    popThrough(id){
      const idx = stack.findIndex(s => s.id === id);
      if (idx === -1) return;
      const removed = stack.splice(idx);
      if (!removed.length) return;
      ignoreNextPop = true;
      history.go(-removed.length);
    }
  };
})();







function closeUserMenu(){
  document.getElementById('userMenu')?.classList.remove('open');
  UIBackStack.pop('userMenu');
}
function closeTipBellDrop(){
  document.getElementById('tipBellDrop')?.classList.remove('open');
  UIBackStack.pop('tipBellDrop');
}
function closeNavDrop(){
  const drop=document.querySelector('.nav-drop');
  drop?.classList.remove('open');
  drop?.classList.remove('force-closed');
  UIBackStack.pop('navDrop');
}
function closeNavDropYear(){
  document.querySelectorAll('.nav-drop .dd-year.open').forEach(y=>y.classList.remove('open'));
  UIBackStack.pop('navDropYear');
}


function closeMobileNav(){
  document.querySelector('.nav-links')?.classList.remove('show');
  
  
  
  
  document.querySelectorAll('.nav-drop .dd-year.open').forEach(y=>y.classList.remove('open'));
  UIBackStack.popThrough('mobileNav');
}
function toggleNav(){
  const links=document.querySelector('.nav-links');
  const opening=!links?.classList.contains('show');
  if(opening){
    closeUserMenu();
    closeTipBellDrop();
    closeNavDrop();
    links?.classList.add('show');
    UIBackStack.push('mobileNav', ()=>{ links?.classList.remove('show'); });
  } else {
    closeMobileNav();
  }
}



function setCourseOpen(card, open){
  card.classList.toggle('open', open);
  card.querySelector('.course-head')?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

document.addEventListener('click', e=>{
  const head = e.target.closest('.course-head');
  if(head){
    const card = head.parentElement;
    setCourseOpen(card, !card.classList.contains('open'));
  }
});


function toggleAll(btn){
  const courses=[...document.querySelectorAll('.course')];
  const anyClosed=courses.some(c=>!c.classList.contains('open'));
  courses.forEach(c=>setCourseOpen(c,anyClosed));
  btn.textContent = anyClosed ? 'إغلاق الكل' : 'فتح الكل';
}


function filterCourses(q){
  q=q.trim().toLowerCase();
  const panels=document.querySelectorAll('.sem-panel');
  if(q && panels.length){ panels.forEach(p=>p.classList.add('show')); }
  else if(panels.length){
    panels.forEach((p,i)=>p.classList.toggle('show', p.dataset.sem==='0'));
    document.querySelectorAll('.sem-tab').forEach((t,i)=>t.classList.toggle('active',i===0));
  }
  document.querySelectorAll('.course').forEach(c=>{
    c.style.display = c.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}


const io=new IntersectionObserver(en=>{
  en.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});
},{threshold:.1});
document.querySelectorAll('.reveal').forEach(el=>io.observe(el));
window.addEventListener('load',()=>setTimeout(()=>{
  document.querySelectorAll('.reveal:not(.in)').forEach(el=>{
    if(el.getBoundingClientRect().top<window.innerHeight) el.classList.add('in');
  });
},350));


function showSem(btn,i){
  btn.parentElement.querySelectorAll('.sem-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.sem-panel').forEach(p=>p.classList.toggle('show',+p.dataset.sem===i));
}


function openFromHash(){
  const h=location.hash;
  
  if(h==='#sem0'||h==='#sem1'){
    const si=h==='#sem1'?1:0;
    const tabs=document.querySelectorAll('.sem-tab');
    document.querySelectorAll('.sem-panel').forEach(p=>p.classList.toggle('show',+p.dataset.sem===si));
    tabs.forEach((t,i)=>t.classList.toggle('active',i===si));
    if(tabs.length) window.scrollTo({top:0,behavior:'smooth'});
    return;
  }
  if(!h || h.indexOf('#c_')!==0) return;
  const target=document.getElementById(h.slice(1));
  if(!target) return;
  const panel=target.closest('.sem-panel');
  if(panel){
    const si=+panel.dataset.sem;
    document.querySelectorAll('.sem-panel').forEach(p=>p.classList.toggle('show',+p.dataset.sem===si));
    document.querySelectorAll('.sem-tab').forEach((t,i)=>t.classList.toggle('active',i===si));
  }
  target.classList.add('open','flash');
  setTimeout(()=>target.scrollIntoView({behavior:'smooth',block:'center'}),180);
  setTimeout(()=>target.classList.remove('flash'),2400);
}
window.addEventListener('DOMContentLoaded',openFromHash);
window.addEventListener('hashchange',openFromHash);



const MORNING_ADHKAR=[
  {t:"أَصْبَحْنَا وَأَصْبَحَ الْمُلْكُ لِلَّهِ، وَالْحَمْدُ لِلَّهِ", s:"من أذكار الصباح"},
  {t:"اللَّهُمَّ بِكَ أَصْبَحْنَا، وَبِكَ أَمْسَيْنَا، وَبِكَ نَحْيَا، وَبِكَ نَمُوتُ، وَإِلَيْكَ النُّشُورُ", s:"من أذكار الصباح"},
  {t:"سُبْحَانَ اللَّهِ وَبِحَمْدِهِ عَدَدَ خَلْقِهِ، وَرِضَا نَفْسِهِ، وَزِنَةَ عَرْشِهِ، وَمِدَادَ كَلِمَاتِهِ", s:"من أذكار الصباح"},
  {t:"اللَّهُمَّ إِنِّي أَسْأَلُكَ عِلْمًا نَافِعًا، وَرِزْقًا طَيِّبًا، وَعَمَلًا مُتَقَبَّلًا", s:"دعاء الصباح"},
  {t:"حَسْبِيَ اللَّهُ لَا إِلَهَ إِلَّا هُوَ، عَلَيْهِ تَوَكَّلْتُ، وَهُوَ رَبُّ الْعَرْشِ الْعَظِيمِ", s:"من أذكار الصباح · سبع مرات"},
];
const EVENING_ADHKAR=[
  {t:"أَمْسَيْنَا وَأَمْسَى الْمُلْكُ لِلَّهِ، وَالْحَمْدُ لِلَّهِ", s:"من أذكار المساء"},
  {t:"اللَّهُمَّ بِكَ أَمْسَيْنَا، وَبِكَ أَصْبَحْنَا، وَبِكَ نَحْيَا، وَبِكَ نَمُوتُ، وَإِلَيْكَ الْمَصِيرُ", s:"من أذكار المساء"},
  {t:"اللَّهُمَّ عَافِنِي فِي بَدَنِي، اللَّهُمَّ عَافِنِي فِي سَمْعِي، اللَّهُمَّ عَافِنِي فِي بَصَرِي", s:"من أذكار المساء"},
  {t:"أَعُوذُ بِكَلِمَاتِ اللَّهِ التَّامَّاتِ مِنْ شَرِّ مَا خَلَقَ", s:"من أذكار المساء · ثلاث مرات"},
  {t:"رَضِيتُ بِاللَّهِ رَبًّا، وَبِالْإِسْلَامِ دِينًا، وَبِمُحَمَّدٍ ﷺ نَبِيًّا", s:"من أذكار المساء"},
];

const NIGHT_ADHKAR=[
  {t:"﴿ تَبَارَكَ الَّذِي بِيَدِهِ الْمُلْكُ وَهُوَ عَلَىٰ كُلِّ شَيْءٍ قَدِيرٌ ﴾", s:"اقرأ سورة المُلك قبل نومك · تنجّي من عذاب القبر"},
  {t:"«مَن قَرَأَ سُورَةَ تَبَارَكَ كُلَّ لَيْلَةٍ مَنَعَهُ اللَّهُ بِهَا مِن عَذَابِ الْقَبْرِ»", s:"تذكير بقراءة سورة المُلك"},
  {t:"بِاسْمِكَ اللَّهُمَّ أَمُوتُ وَأَحْيَا", s:"من أذكار النوم"},
  {t:"اللَّهُمَّ قِنِي عَذَابَكَ يَوْمَ تَبْعَثُ عِبَادَكَ", s:"من أذكار النوم · ثلاث مرات"},
  {t:"آيةُ الكرسيّ: ﴿ اللَّهُ لَا إِلَٰهَ إِلَّا هُوَ الْحَيُّ الْقَيُّومُ ﴾", s:"من قرأها عند نومه لم يزل عليه من الله حافظ"},
  {t:"باسْمِكَ رَبِّي وَضَعْتُ جَنْبِي، وَبِكَ أَرْفَعُهُ", s:"من أذكار النوم"},
  {t:"سُبْحَانَ اللَّهِ (33)، الْحَمْدُ لِلَّهِ (33)، اللَّهُ أَكْبَرُ (34)", s:"تسبيح ما قبل النوم"},
];

function pick(arr){ 
  const now=new Date(); const day=Math.floor((now-new Date(now.getFullYear(),0,0))/86400000);
  return arr[(day+now.getHours())%arr.length];
}

function showToast(kind,text,sub,mins,link){
  const safeKind=PTCUtils.escapeHTML(kind),safeText=PTCUtils.escapeHTML(text),safeSub=PTCUtils.escapeHTML(sub);
  let wrap=document.querySelector('.toast-wrap');
  if(!wrap){wrap=document.createElement('div');wrap.className='toast-wrap';document.body.appendChild(wrap);}
  const dur=(mins||45)*1000; 
  const el=document.createElement('div');
  el.className='toast'+(link?' clickable':'');
  el.innerHTML=`<div class="toast-glow"></div>
    <div class="th"><span class="tkind"><span class="tdot"></span>${safeKind}</span><button class="tclose" aria-label="إغلاق">×</button></div>
    <div class="ttext">${safeText}</div><div class="tsub">${safeSub}</div>
    ${link?'<div class="tgo">اضغط للانتقال ←</div>':''}
    <div class="toast-progress" style="animation-duration:${dur}ms"></div>`;
  wrap.appendChild(el);
  requestAnimationFrame(()=>el.classList.add('show'));
  const close=()=>{el.classList.add('closing');el.classList.remove('show');setTimeout(()=>el.remove(),550);};
  el.querySelector('.tclose').onclick=(e)=>{ e.stopPropagation(); close(); };
  if(link){
    el.addEventListener('click',e=>{
      if(e.target.closest('.tclose')) return;
      location.href=link;
    });
  }
  setTimeout(close,dur);
}


function maybeRemind(fajr,asr,maghrib){
  const now=new Date();
  const mins=now.getHours()*60+now.getMinutes();
  const toMin=t=>{const[h,m]=t.split(':').map(Number);return h*60+m;};
  let kind,category,item,dur=45,link='rawdah.html';
  const eightAM=8*60, ninePM=21*60, elevenPM=23*60;
  if(mins>=ninePM && mins<elevenPM){                       
    const v=pick(NIGHT_ADHKAR); kind="قبل النوم"; category='night'; item={t:v.t,s:v.s}; dur=75; link='rawdah.html#night';
  } else if(fajr && mins>=toMin(fajr) && mins<eightAM){     
    const v=pick(MORNING_ADHKAR); kind="أذكار الصباح"; category='morning'; item={t:v.t,s:v.s}; dur=60; link='rawdah.html#morning';
  } else if(asr && maghrib && mins>=toMin(asr) && mins<toMin(maghrib)){ 
    const v=pick(EVENING_ADHKAR); kind="أذكار المساء"; category='evening'; item={t:v.t,s:v.s}; dur=60; link='rawdah.html#evening';
  } else {                                                  
    const v=pick(DAILY.length?DAILY:[{k:"ذِكر",a:"سُبْحَانَ اللَّهِ",s:""}]);
    kind="الورد اليومي · "+v.k; category='daily'; item={t:v.a,s:v.s}; dur=45; link='rawdah.html#quran';
  }
  
  const dateStr=`${now.getFullYear()}-${now.getMonth()+1}-${now.getDate()}`;
  const key=`ptc_reminder_${category}_${dateStr}`;
  try{
    if(localStorage.getItem(key)) return;
    localStorage.setItem(key,'1');
    
    for(let i=localStorage.length-1;i>=0;i--){
      const k=localStorage.key(i);
      if(k&&k.indexOf('ptc_reminder_')===0&&!k.endsWith(dateStr)) localStorage.removeItem(k);
    }
  }catch(e){}
  setTimeout(()=>showToast(kind,item.t,item.s,dur,link), 2600);
}


const GAZA_COORDS={lat:31.5017,lng:34.4668};
function getLocationOnce(cb){ cb(GAZA_COORDS.lat,GAZA_COORDS.lng); }

(function initReminder(){
  
  
  
  if(document.body.dataset.minimalNav) return;
  fetch(`https://api.aladhan.com/v1/timings?latitude=${GAZA_COORDS.lat}&longitude=${GAZA_COORDS.lng}&method=4`)
    .then(r=>r.json()).then(d=>{const t=d.data.timings;maybeRemind(t.Fajr,t.Asr,t.Maghrib);})
    .catch(()=>maybeRemind(null,null,null));
})();


const DAILY=[
  {k:"آية", a:"﴿ وَقُل رَّبِّ زِدْنِي عِلْمًا ﴾", s:"طه: 114"},
  {k:"آية", a:"﴿ إِنَّمَا يَخْشَى اللَّهَ مِنْ عِبَادِهِ الْعُلَمَاءُ ﴾", s:"فاطر: 28"},
  {k:"آية", a:"﴿ يَرْفَعِ اللَّهُ الَّذِينَ آمَنُوا مِنكُمْ وَالَّذِينَ أُوتُوا الْعِلْمَ دَرَجَاتٍ ﴾", s:"المجادلة: 11"},
  {k:"حديث", a:"«مَن سَلَكَ طَرِيقًا يَلْتَمِسُ فِيهِ عِلْمًا سَهَّلَ اللَّهُ لَهُ بِهِ طَرِيقًا إِلَى الْجَنَّةِ»", s:"رواه مسلم"},
  {k:"آية", a:"﴿ وَمَن يَتَّقِ اللَّهَ يَجْعَل لَّهُ مَخْرَجًا ﴾", s:"الطلاق: 2"},
  {k:"آية", a:"﴿ إِنَّ مَعَ الْعُسْرِ يُسْرًا ﴾", s:"الشرح: 6"},
  {k:"حديث", a:"«إِذَا مَاتَ الإِنْسَانُ انْقَطَعَ عَنْهُ عَمَلُهُ إِلَّا مِنْ ثَلَاثٍ... أَوْ عِلْمٍ يُنْتَفَعُ بِهِ»", s:"رواه مسلم"},
  {k:"ذِكر", a:"«لَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللَّهِ»", s:"كنز من كنوز الجنة"},
  {k:"آية", a:"﴿ وَأَن لَّيْسَ لِلْإِنسَانِ إِلَّا مَا سَعَىٰ ﴾", s:"النجم: 39"},
  {k:"دعاء", a:"«اللَّهُمَّ انْفَعْنِي بِمَا عَلَّمْتَنِي، وَعَلِّمْنِي مَا يَنْفَعُنِي، وَزِدْنِي عِلْمًا»", s:"رواه ابن ماجه"},
  {k:"آية", a:"﴿ وَقُلِ اعْمَلُوا فَسَيَرَى اللَّهُ عَمَلَكُمْ وَرَسُولُهُ وَالْمُؤْمِنُونَ ﴾", s:"التوبة: 105"},
  {k:"حديث", a:"«طَلَبُ الْعِلْمِ فَرِيضَةٌ عَلَى كُلِّ مُسْلِمٍ»", s:"رواه ابن ماجه"},
  {k:"ذِكر", a:"«سُبْحَانَ اللَّهِ وَبِحَمْدِهِ، سُبْحَانَ اللَّهِ الْعَظِيمِ»", s:"حبيبتان إلى الرحمن"},
  {k:"آية", a:"﴿ رَبَّنَا آتِنَا فِي الدُّنْيَا حَسَنَةً وَفِي الْآخِرَةِ حَسَنَةً ﴾", s:"البقرة: 201"},
  {k:"حديث", a:"«مَن دَلَّ عَلَى خَيْرٍ فَلَهُ مِثْلُ أَجْرِ فَاعِلِهِ»", s:"رواه مسلم"},
  {k:"آية", a:"﴿ وَتَوَكَّلْ عَلَى اللَّهِ ۚ وَكَفَىٰ بِاللَّهِ وَكِيلًا ﴾", s:"النساء: 81"},
  {k:"ذِكر", a:"«حَسْبُنَا اللَّهُ وَنِعْمَ الْوَكِيلُ»", s:"عند الشدائد"},
  {k:"دعاء", a:"«رَبِّ اشْرَحْ لِي صَدْرِي وَيَسِّرْ لِي أَمْرِي»", s:"طه: 25-26"},
  {k:"آية", a:"﴿ إِنَّ اللَّهَ مَعَ الصَّابِرِينَ ﴾", s:"البقرة: 153"},
  {k:"حديث", a:"«الْمُؤْمِنُ الْقَوِيُّ خَيْرٌ وَأَحَبُّ إِلَى اللَّهِ مِنَ الْمُؤْمِنِ الضَّعِيفِ»", s:"رواه مسلم"},
  {k:"آية", a:"﴿ فَاذْكُرُونِي أَذْكُرْكُمْ وَاشْكُرُوا لِي وَلَا تَكْفُرُونِ ﴾", s:"البقرة: 152"},
  {k:"آية", a:"﴿ وَمَا تَوْفِيقِي إِلَّا بِاللَّهِ ۚ عَلَيْهِ تَوَكَّلْتُ وَإِلَيْهِ أُنِيبُ ﴾", s:"هود: 88"},
  {k:"دعاء", a:"«اللَّهُمَّ لَا سَهْلَ إِلَّا مَا جَعَلْتَهُ سَهْلًا، وَأَنْتَ تَجْعَلُ الْحَزْنَ إِذَا شِئْتَ سَهْلًا»", s:"رواه ابن حبان"},
  {k:"حديث", a:"«إِنَّ اللَّهَ يُحِبُّ إِذَا عَمِلَ أَحَدُكُمْ عَمَلًا أَنْ يُتْقِنَهُ»", s:"رواه البيهقي"},
  {k:"آية", a:"﴿ وَبَشِّرِ الصَّابِرِينَ ﴾", s:"البقرة: 155"},
  {k:"ذِكر", a:"«لَا إِلَهَ إِلَّا اللَّهُ وَحْدَهُ لَا شَرِيكَ لَهُ»", s:"أفضل ما قاله النبيون"},
  {k:"دعاء", a:"«اللَّهُمَّ أَعِنِّي عَلَى ذِكْرِكَ وَشُكْرِكَ وَحُسْنِ عِبَادَتِكَ»", s:"رواه أبو داود"},
  {k:"آية", a:"﴿ قُلْ هَلْ يَسْتَوِي الَّذِينَ يَعْلَمُونَ وَالَّذِينَ لَا يَعْلَمُونَ ﴾", s:"الزمر: 9"},
  {k:"حديث", a:"«مَنْ غَدَا إِلَى الْمَسْجِدِ لَا يُرِيدُ إِلَّا أَنْ يَتَعَلَّمَ خَيْرًا... كَانَ لَهُ كَأَجْرِ حَاجٍّ تَامًّا حَجَّتُهُ»", s:"رواه الطبراني"},
  {k:"آية", a:"﴿ وَاللَّهُ يَعْلَمُ وَأَنتُمْ لَا تَعْلَمُونَ ﴾", s:"البقرة: 216"},
  {k:"دعاء", a:"«رَبِّ زِدْنِي عِلْمًا وَفَهْمًا، وَأَلْحِقْنِي بِالصَّالِحِينَ»", s:"من أدعية طلب العلم"},
];
(function(){
  const el=document.getElementById('ayah'), su=document.getElementById('surah'), kd=document.getElementById('kind');
  if(!el) return;
  const now=new Date();
  const start=new Date(now.getFullYear(),0,0);
  const day=Math.floor((now-start)/86400000);
  const v=DAILY[day % DAILY.length];
  el.textContent=v.a; if(su) su.textContent=v.s; if(kd) kd.textContent=v.k;
})();


(function(){
  const input=document.getElementById('homeSearch');
  const box=document.getElementById('hsResults');
  if(!input || typeof COURSE_INDEX==='undefined') return;
  let active=-1, current=[], searchCatalog=[...COURSE_INDEX];
  async function refreshSearchCatalog(){
    if(typeof PTCAuth==='undefined') return;
    try{
      const apiCourses=await PTCAuth.getCourses();
      const years=['','السنة الأولى','السنة الثانية','السنة الثالثة','السنة الرابعة'];
      const mapped=apiCourses.map(c=>({id:c.key,code:c.code,en:c.name_en||c.name_ar,ar:c.name_ar||c.name_en,year:years[Number(c.year)]||`السنة ${c.year}`,sname:Number(c.semester)===3?'اختياري':`الفصل ${Number(c.semester)===2?'الثاني':'الأول'}`,page:c.page||(Number(c.semester)===3?'electives.html':`year${c.year}.html`),kw:c.keywords||''}));
      const byId=new Map(searchCatalog.map(c=>[c.id,c]));mapped.forEach(c=>byId.set(c.id,{...(byId.get(c.id)||{}),...c}));searchCatalog=[...byId.values()];
    }catch(error){console.error(error);}
  }
  window.addEventListener('ptc-auth-change',refreshSearchCatalog);
  window.addEventListener('DOMContentLoaded',refreshSearchCatalog);
  function render(list){
    current=list; active=-1;
    if(!list.length){ box.innerHTML='<div class="hs-empty">ما في مساق بهذا الاسم</div>'; box.classList.add('show'); return; }
    
    
    box.innerHTML=list.map((c,i)=>`<div class="hs-item" data-i="${i}" onclick="hsGo(${i})">
        <span class="hs-code">${PTCUtils.escapeHTML(c.code)}</span>
        <span class="hs-meta"><span class="hs-en">${PTCUtils.escapeHTML(c.en)}</span><span class="hs-ar">${PTCUtils.escapeHTML(c.ar)}</span></span>
        <span class="hs-where">${PTCUtils.escapeHTML(c.year)} · ${PTCUtils.escapeHTML(c.sname)}</span>
      </div>`).join('');
    box.classList.add('show');
  }
  window.hsGo=function(i){
    const c=current[i]; if(!c) return;
    
    
    const ref=PTCUtils.safeRef(c.id);
    location.href=PTCUtils.safePage(c.page)+(ref?'#'+ref:'');
  };
  window.clearSearch=function(){
    input.value=''; box.classList.remove('show');
    const clr=document.getElementById('hsClear'); if(clr) clr.classList.remove('show');
    input.focus();
  };
  input.addEventListener('input',()=>{
    const q=input.value.trim().toLowerCase();
    const clr=document.getElementById('hsClear');
    if(clr) clr.classList.toggle('show', input.value.length>0);
    if(!q){ box.classList.remove('show'); return; }
    const list=searchCatalog.filter(c=>
      c.code.toLowerCase().includes(q)||c.en.toLowerCase().includes(q)||c.ar.includes(q)||(c.kw&&c.kw.toLowerCase().includes(q))
    ).slice(0,8);
    render(list);
  });
  input.addEventListener('keydown',e=>{
    const items=[...box.querySelectorAll('.hs-item')];
    if(e.key==='ArrowDown'){e.preventDefault();active=Math.min(active+1,items.length-1);}
    else if(e.key==='ArrowUp'){e.preventDefault();active=Math.max(active-1,0);}
    else if(e.key==='Enter'){ if(active>=0) hsGo(active); else if(current.length) hsGo(0); return;}
    else return;
    items.forEach((it,i)=>it.classList.toggle('active',i===active));
    if(items[active]) items[active].scrollIntoView({block:'nearest'});
  });
  document.addEventListener('click',e=>{ if(!e.target.closest('.home-search')) box.classList.remove('show'); });
})();


(function(){
  const dayEl=document.getElementById('dtDay'), gregEl=document.getElementById('dtGreg'),
        hijriEl=document.getElementById('dtHijri'), clockEl=document.getElementById('dtClock');
  if(!clockEl) return;
  const days=["الأحد","الإثنين","الثلاثاء","الأربعاء","الخميس","الجمعة","السبت"];
  function tick(){
    const now=new Date();
    if(dayEl) dayEl.textContent=days[now.getDay()];
    if(gregEl) gregEl.textContent=now.toLocaleDateString('ar-EG-u-nu-latn',{day:'numeric',month:'long',year:'numeric'});
    if(hijriEl){
      try{ hijriEl.textContent=new Intl.DateTimeFormat('ar-SA-u-ca-islamic-nu-latn',{day:'numeric',month:'long',year:'numeric'}).format(now); }
      catch(e){ hijriEl.textContent=''; }
    }
    let h=now.getHours();
    const ampm = h<12 ? 'ص' : 'م';
    h = h%12; if(h===0) h=12;
    const hh=String(h).padStart(2,'0');
    const mm=String(now.getMinutes()).padStart(2,'0');
    const ss=String(now.getSeconds()).padStart(2,'0');
    clockEl.innerHTML=`${hh}:${mm}:${ss} <span style="font-size:.6em;color:var(--copper)">${ampm}</span>`;
  }
  tick(); setInterval(tick,1000);
})();


(function(){
  const nameEl=document.getElementById('pcName');
  if(!nameEl) return;
  const timeEl=document.getElementById('pcTime'), countEl=document.getElementById('pcCount');
  const GAZA={lat:31.5017,lng:34.4668};
  const NAMES={Fajr:'الفجر',Dhuhr:'الظهر',Asr:'العصر',Maghrib:'المغرب',Isha:'العشاء'};
  const ORDER=['Fajr','Dhuhr','Asr','Maghrib','Isha'];
  let times=null;
  function loadDay(offset){
    const d=new Date(); d.setDate(d.getDate()+offset);
    const dd=String(d.getDate()).padStart(2,'0'), mm=String(d.getMonth()+1).padStart(2,'0'), yy=d.getFullYear();
    return fetch(`https://api.aladhan.com/v1/timings/${dd}-${mm}-${yy}?latitude=${GAZA.lat}&longitude=${GAZA.lng}&method=4`)
      .then(r=>r.json()).then(j=>j.data.timings);
  }
  function toDate(base,hhmm){const[h,m]=hhmm.split(':').map(Number);const d=new Date(base);d.setHours(h,m,0,0);return d;}
  function computeNext(){
    const now=new Date();
    let next=null,nname=null;
    if(times){
      for(const k of ORDER){
        const t=toDate(now,times[k]);
        if(t>now){ next=t; nname=k; break; }
      }
    }
    if(!next && window._tomorrowFajr){ next=window._tomorrowFajr; nname='Fajr'; }
    return next?{when:next,name:nname}:null;
  }
  function render(){
    const nx=computeNext();
    if(!nx){ return; }
    nameEl.textContent=NAMES[nx.name];
    let ph=nx.when.getHours(); const pap = ph<12?'ص':'م'; ph=ph%12; if(ph===0)ph=12;
    const h=String(ph).padStart(2,'0'), m=String(nx.when.getMinutes()).padStart(2,'0');
    timeEl.textContent=`الأذان ${h}:${m} ${pap}`;
    let diff=Math.max(0,Math.floor((nx.when-new Date())/1000));
    const hh=String(Math.floor(diff/3600)).padStart(2,'0');
    const mm=String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const ss=String(diff%60).padStart(2,'0');
    countEl.innerHTML=`${hh}:${mm}:${ss}`;
    if(diff===0){ setTimeout(init,60000); } 
  }
  function init(){
    loadDay(0).then(t=>{
      times=t;
      return loadDay(1);
    }).then(t2=>{
      const d=new Date(); d.setDate(d.getDate()+1);
      window._tomorrowFajr=toDate(d,t2.Fajr);
      render();
    }).catch(()=>{ nameEl.textContent='تعذّر جلب المواعيد'; });
  }
  init();
  setInterval(render,1000);
})();


(function(){
  const list=document.getElementById('mcList');
  if(!list || typeof COURSE_INDEX==='undefined') return;

  const PRIMARY_ADMIN_EMAIL='ptchub.duckdns.org@gmail.com';
  function isPrimaryAdmin(){
    return typeof PTCAuth!=='undefined' && PTCAuth.user
      && String(PTCAuth.user.email||'').toLowerCase().trim()===PRIMARY_ADMIN_EMAIL;
  }

  let cache=[];
  let enrolled=[];
  let catalog=[...COURSE_INDEX];
  const cloud=()=> typeof PTCAuth!=='undefined' && PTCAuth.enabled() && PTCAuth.user;
  const courseByRef=ref=>catalog.find(x=>x.id===ref)||catalog.find(x=>x.code===ref);
  async function refreshCatalog(){
    if(typeof PTCAuth==='undefined') return;
    try{
      const apiCourses=await PTCAuth.getCourses();
      const years=['','السنة الأولى','السنة الثانية','السنة الثالثة','السنة الرابعة'];
      const mapped=apiCourses.map(c=>({
        id:c.key,code:c.code,en:c.name_en||c.name_ar,ar:c.name_ar||c.name_en,
        year:years[Number(c.year)]||`السنة ${c.year}`,
        sname:Number(c.semester)===3?'اختياري':`الفصل ${Number(c.semester)===2?'الثاني':'الأول'}`,
        page:c.page||(Number(c.semester)===3?'electives.html':`year${c.year}.html`),kw:c.keywords||''
      }));
      const byId=new Map(catalog.map(c=>[c.id,c]));mapped.forEach(c=>byId.set(c.id,{...(byId.get(c.id)||{}),...c}));catalog=[...byId.values()];
    }catch(error){console.error(error);}
  }
  const normalize=items=>[...new Set((items||[]).map(ref=>courseByRef(ref)?.id).filter(Boolean))];

  async function get(){
    if(cloud()) return PTCAuth.getMyCourseLists().then(lists=>({
      all:normalize(lists.all), current:normalize(lists.current),
    }));
    let local=[];
    try{local=normalize(JSON.parse(localStorage.getItem('ptc_mycourses')||'[]'));}catch(e){}
    return {all:local,current:local};
  }
  function setLocal(a){ try{localStorage.setItem('ptc_mycourses',JSON.stringify(normalize(a)));}catch(e){} }
  
  function renderCount(n){
    const box=document.getElementById('mcHeadCount');
    if(box) box.textContent = n ? ` (${Number(n)})` : '';
  }
  async function render(){
    
    const card=list.closest('.mycourses-card');
    if(isPrimaryAdmin()){
      if(card) card.style.display='none';
      return;
    }
    if(card) card.style.display='';

    try{ const lists=await get(); cache=lists.current; enrolled=lists.all; }
    catch(e){ console.error(e); renderCount(0); list.innerHTML='<div class="mc-empty">تعذّر تحميل المساقات. حاول مجدداً.</div>'; return; }
    renderCount(cache.length);
    if(!cache.length){ list.innerHTML='<div class="mc-empty">ما أضفت مساقات بعد</div>'; return; }
    list.innerHTML=cache.map(ref=>{
      const c=courseByRef(ref); if(!c) return '';
      
      const id=PTCUtils.safeRef(c.id); if(!id) return '';
      
      return `<div class="mc-item"><a href="course.html?course=${id}" title="${PTCUtils.escapeHTML(c.en)}">${PTCUtils.escapeHTML(c.ar)}</a><button class="mc-del" onclick="mcRemove('${id}')" aria-label="حذف">×</button></div>`;
    }).join('');
  }
  window.mcRemove=async function(ref){
    try{
      if(cloud()) await PTCAuth.removeMyCourse(ref);
      else setLocal((await get()).all.filter(x=>x!==ref));
      render();
    }catch(e){ console.error(e); alert('تعذّر حذف المساق. حاول مجدداً.'); }
  };
  
  const drawer=document.getElementById('mcDrawer');
  const drawerBack=document.getElementById('mcDrawerOverlay');
  window.mcOpenAdd=function(){
    if(!drawer) return;
    const inp=document.getElementById('mcSearch');
    drawer.classList.add('is-open');
    if(drawerBack) drawerBack.classList.add('is-open');
    document.body.style.overflow='hidden';
    inp.value=''; mcFilter(''); inp.focus();
  };
  window.mcCloseAdd=function(){
    if(!drawer||!drawer.classList.contains('is-open')) return;
    drawer.classList.remove('is-open');
    if(drawerBack) drawerBack.classList.remove('is-open');
    document.body.style.overflow='';
    const btn=document.getElementById('mcAddBtn');
    if(btn) btn.focus();
  };
  if(drawer) document.addEventListener('keydown',e=>{ if(e.key==='Escape') mcCloseAdd(); });
  
  function courseCount(n){
    if(n===1) return 'مساق واحد';
    if(n===2) return 'مساقان';
    if(n<=10) return `${Number(n)} مساقات`;
    return `${Number(n)} مساقًا`;
  }
  window.mcFilter=function(q){
    
    const opts=PTCCourseSearch.filter(catalog,q,enrolled);
    const count=document.getElementById('mcCount');
    if(count) count.textContent = opts.length ? courseCount(opts.length) : '';
    document.getElementById('mcOptions').innerHTML = opts.length
      ? opts.map(c=>{
          const id=PTCUtils.safeRef(c.id); if(!id) return '';
          return `<button class="mc-opt" onclick="mcAdd('${id}')"><span>${PTCUtils.escapeHTML(c.ar)}</span><code>${PTCUtils.escapeHTML(c.code)}</code></button>`;
        }).join('')
      : '<div class="mc-empty">لا نتائج</div>';
  };
  window.mcAdd=async function(ref){
    try{
      if(cloud()) await PTCAuth.addMyCourse(ref);
      else { const mine=(await get()).all; if(!mine.includes(ref)) mine.push(ref); setLocal(mine); }
      await render();
      
      mcFilter(document.getElementById('mcSearch').value);
    }catch(e){ console.error(e); alert('تعذّرت إضافة المساق. حاول مجدداً.'); }
  };
  (async()=>{await refreshCatalog();await render();})();
  window.addEventListener('ptc-auth-change',async()=>{await refreshCatalog();await render();});
})();


(function(){
  if(typeof PTCAuth==='undefined' || typeof PTCCourseContent!=='undefined') return;

  

  function kind(file){
    const value=String(file.kind||'other').toLowerCase();
    return ['pdf','doc','vid','zip','link'].includes(value)?value:'link';
  }

  async function openFile(id){
    try{ await PTCAuth.openCourseFile(id); }
    catch(error){ alert(error?.message||'تعذّر فتح الملف.'); }
  }
  window.ptcOpenCourseFile=openFile;

  async function loadCourseFiles(card){
    const box=card.querySelector('.files');
    if(!box||card.dataset.filesLoaded==='1') return;
    card.dataset.filesLoaded='1';
    box.innerHTML='<div class="empty-note">جارٍ تحميل الملفات...</div>';
    try{
      const files=await PTCAuth.getCourseFiles(card.id);
      if(!files.length){
        box.innerHTML='<div class="empty-note"><i data-icon="paperclip"></i> لسا ما انضافت ملفات لهاي المادة — قريباً</div>';
        return;
      }
      box.innerHTML=files.map(file=>`<button class="file" type="button" onclick="ptcOpenCourseFile(${Number(file.id)})" style="width:100%;background:none;text-align:start;cursor:pointer">
        <span class="ic ${kind(file)}">${PTCUtils.escapeHTML(String(file.kind||'FILE').toUpperCase())}</span>
        <span class="fname">${PTCUtils.escapeHTML(file.title||'ملف')}</span>
      </button>`).join('');
    }catch(error){
      card.dataset.filesLoaded='0';
      box.innerHTML='<div class="empty-note">تعذّر تحميل الملفات. تأكد من اتصال الباك إند.</div>';
      console.error(error);
    }
  }

  window.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('.course[id]').forEach(card=>{
      const head=card.querySelector('.course-head');
      if(head) head.addEventListener('click',()=>setTimeout(()=>loadCourseFiles(card),0));
    });
    const hash=location.hash.slice(1);
    const selected=hash&&document.getElementById(hash);
    if(selected) loadCourseFiles(selected);
    const expand=document.querySelector('.expand-btn');
    if(expand) expand.addEventListener('click',()=>setTimeout(()=>{
      document.querySelectorAll('.course.open').forEach(loadCourseFiles);
    },0));
  });
})();


(function(){
  
  if(document.getElementById('sideStack')) return;
  if(!document.querySelector('.nav')) return; 
  
  if(document.body.dataset.minimalNav) return;
  const wrap=document.createElement('div');
  wrap.className='mini-widget';
  wrap.innerHTML=`
    <button class="mw-toggle" id="mwToggle" aria-label="التاريخ والصلاة"><svg class="ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></button>
    <div class="mw-panel" id="mwPanel">
      <div class="mw-time" id="mwClock">--:--:--</div>
      <div class="mw-day" id="mwDay">—</div>
      <div class="mw-line"></div>
      <div class="mw-greg" id="mwGreg">—</div>
      <div class="mw-hijri" id="mwHijri">—</div>
      <div class="mw-line"></div>
      <div class="mw-prayer"><span class="mw-pname" id="mwPName">—</span><span class="mw-pcount" id="mwPCount">--:--:--</span></div>
      <div class="mw-premain">المتبقّي للأذان القادم · غزة</div>
    </div>`;
  document.body.appendChild(wrap);
  document.getElementById('mwToggle').onclick=()=>wrap.classList.toggle('open');

  
  document.addEventListener('click',e=>{
    if(wrap.classList.contains('open') && !wrap.contains(e.target)){
      wrap.classList.remove('open');
    }
  });
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape') wrap.classList.remove('open');
  });

  const days=["الأحد","الإثنين","الثلاثاء","الأربعاء","الخميس","الجمعة","السبت"];
  function clock(){
    const now=new Date();
    document.getElementById('mwDay').textContent=days[now.getDay()];
    document.getElementById('mwGreg').textContent=now.toLocaleDateString('ar-EG-u-nu-latn',{day:'numeric',month:'long',year:'numeric'});
    try{document.getElementById('mwHijri').textContent=new Intl.DateTimeFormat('ar-SA-u-ca-islamic-nu-latn',{day:'numeric',month:'long',year:'numeric'}).format(now);}catch(e){}
    let h=now.getHours();const ap=h<12?'ص':'م';h=h%12||12;
    document.getElementById('mwClock').innerHTML=`${String(h).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')} <span style="font-size:.6em;color:var(--copper)">${ap}</span>`;
  }
  clock(); setInterval(clock,1000);

  
  const NAMES={Fajr:'الفجر',Dhuhr:'الظهر',Asr:'العصر',Maghrib:'المغرب',Isha:'العشاء'};
  const ORDER=['Fajr','Dhuhr','Asr','Maghrib','Isha'];
  let T=null, tomF=null;
  function toDate(base,hhmm){const[h,m]=hhmm.split(':').map(Number);const d=new Date(base);d.setHours(h,m,0,0);return d;}
  function loadDay(off){const d=new Date();d.setDate(d.getDate()+off);const dd=String(d.getDate()).padStart(2,'0'),mm=String(d.getMonth()+1).padStart(2,'0');return fetch(`https://api.aladhan.com/v1/timings/${dd}-${mm}-${d.getFullYear()}?latitude=31.5017&longitude=34.4668&method=4`).then(r=>r.json()).then(j=>j.data.timings);}
  function nextP(){const now=new Date();if(T){for(const k of ORDER){const t=toDate(now,T[k]);if(t>now)return{when:t,name:k};}}if(tomF)return{when:tomF,name:'Fajr'};return null;}
  function pRender(){const nx=nextP();if(!nx)return;document.getElementById('mwPName').textContent=NAMES[nx.name];let diff=Math.max(0,Math.floor((nx.when-new Date())/1000));const hh=String(Math.floor(diff/3600)).padStart(2,'0'),mm=String(Math.floor((diff%3600)/60)).padStart(2,'0'),ss=String(diff%60).padStart(2,'0');document.getElementById('mwPCount').textContent=`${hh}:${mm}:${ss}`;if(diff===0)setTimeout(pInit,60000);}
  function pInit(){loadDay(0).then(t=>{T=t;return loadDay(1);}).then(t2=>{const d=new Date();d.setDate(d.getDate()+1);tomF=toDate(d,t2.Fajr);pRender();}).catch(()=>{document.getElementById('mwPName').textContent='—';});}
  pInit(); setInterval(pRender,1000);
})();


(function(){
  const drop=document.querySelector('.nav-drop');
  const menu=document.getElementById('menu');

  
  document.addEventListener('click',e=>{
    if(drop && !e.target.closest('.nav-drop')) closeNavDrop();
    if(!e.target.closest('.nav-links') && !e.target.closest('.burger')){
      
      
      
      if(menu && menu.classList.contains('show')) closeMobileNav();
    }
  });
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      closeNavDrop();
      if(menu && menu.classList.contains('show')) closeMobileNav();
    }
  });

  if(!drop) return;
  const isMobile=()=>window.matchMedia('(max-width:820px)').matches;

  
  
  
  
  
  
  const btn=drop.querySelector('.nav-drop-btn');
  btn.addEventListener('click',e=>{
    e.stopPropagation();
    
    
    
    closeUserMenu();
    closeTipBellDrop();
    const wasOpen=drop.classList.contains('open');
    if(wasOpen){
      closeNavDrop();
    } else {
      drop.classList.add('open');
      if(!isMobile()){
        UIBackStack.push('navDrop', ()=>{ drop.classList.remove('open'); drop.classList.remove('force-closed'); });
      }
    }
    
    
    drop.classList.toggle('force-closed',wasOpen);
  });
  
  
  drop.addEventListener('mouseleave',()=>{
    drop.classList.remove('force-closed');
  });
  
  
  
  
  drop.addEventListener('mouseenter',()=>{
    closeUserMenu();
    closeTipBellDrop();
  });

  
  
  
  
  
  
  
  
  drop.querySelectorAll('.dd-year').forEach(y=>{
    const link=y.querySelector('.dd-year-link');
    link.addEventListener('click',e=>{
      if(isMobile() && !y.classList.contains('open')){
        e.preventDefault();            
        const alreadyTracked=!!drop.querySelector('.dd-year.open');
        drop.querySelectorAll('.dd-year').forEach(o=>{ if(o!==y) o.classList.remove('open'); });
        y.classList.add('open');
        if(!alreadyTracked){
          UIBackStack.push('navDropYear', ()=>{
            drop.querySelectorAll('.dd-year.open').forEach(o=>o.classList.remove('open'));
          });
        }
      }
    });
  });

})();



const QuranAudio=(function(){
  const KEY='ptc_audio_state';
  let audio=null, queue=[], idx=0, page=null, playing=false, wantsPlayback=false;
  let lastSavedAt=0;

  function ensure(){
    if(audio) return audio;
    audio=new Audio();
    audio.preload='auto';

    audio.addEventListener('play',()=>{ playing=true; wantsPlayback=true; save(true); emit(); });
    audio.addEventListener('pause',()=>{ playing=false; save(true); emit(); });
    audio.addEventListener('timeupdate',()=>{
      const now=Date.now();
      if(now-lastSavedAt>1000){ lastSavedAt=now; save(); updateProgress(); }
    });
    audio.addEventListener('ended',()=>{
      idx++;
      if(idx<queue.length){
        audio.src=queue[idx];
        audio.currentTime=0;
        audio.play().catch(()=>{ playing=false; emit(); });
      }else{
        playing=false;
        wantsPlayback=false;
        save(true);
        emit();
      }
    });
    audio.addEventListener('error',()=>{
      idx++;
      if(idx<queue.length){
        audio.src=queue[idx];
        audio.play().catch(()=>{ playing=false; emit(); });
      }else{
        playing=false;
        wantsPlayback=false;
        save(true);
        emit();
      }
    });
    return audio;
  }

  function state(){
    return {
      queue,
      idx,
      page,
      playing,
      wantsPlayback,
      t:audio?.currentTime||0
    };
  }

  function save(force=false){
    if(!queue.length) return;
    try{ sessionStorage.setItem(KEY,JSON.stringify(state())); }catch(e){}
  }

  function emit(){
    window.dispatchEvent(new CustomEvent('ptc-audio-change'));
    renderBar();
  }

  function restore(){
    try{
      const saved=JSON.parse(sessionStorage.getItem(KEY)||'null');
      if(!saved||!Array.isArray(saved.queue)||!saved.queue.length) return;

      queue=saved.queue;
      idx=Math.max(0,Math.min(Number(saved.idx)||0,queue.length-1));
      page=Number(saved.page)||null;
      wantsPlayback=Boolean(saved.wantsPlayback ?? saved.playing);

      ensure();
      audio.src=queue[idx];
      audio.addEventListener('loadedmetadata',()=>{
        try{ audio.currentTime=Math.max(0,Number(saved.t)||0); }catch(e){}
      },{once:true});

      renderBar();
      if(wantsPlayback){
        audio.play().catch(()=>{
          
          
          playing=false;
          emit();
        });
      }
    }catch(e){}
  }

  function playPage(p){
    page=Math.max(1,Math.min(604,Number(p)||1));
    idx=0;
    playing=false;
    wantsPlayback=true;
    ensure();
    renderBar();

    fetch(`https://api.alquran.cloud/v1/page/${page}/ar.alafasy`)
      .then(r=>{
        if(!r.ok) throw new Error('تعذّر تحميل التلاوة.');
        return r.json();
      })
      .then(d=>{
        queue=(d.data?.ayahs||[]).map(a=>a.audio).filter(Boolean);
        if(!queue.length) throw new Error('لا يوجد صوت لهذه الصفحة.');
        idx=0;
        audio.src=queue[0];
        audio.currentTime=0;
        save(true);
        return audio.play();
      })
      .catch(()=>{
        playing=false;
        emit();
      });
  }

  function pause(){
    wantsPlayback=false;
    if(audio) audio.pause();
    playing=false;
    save(true);
    emit();
  }

  function resume(){
    if(!queue.length) return;
    wantsPlayback=true;
    ensure();
    if(!audio.src) audio.src=queue[idx];
    audio.play().catch(()=>{ playing=false; emit(); });
  }

  function stop(){
    wantsPlayback=false;
    if(audio){ audio.pause(); audio.removeAttribute('src'); audio.load(); }
    queue=[]; idx=0; playing=false; page=null;
    try{ sessionStorage.removeItem(KEY); }catch(e){}
    emit();
  }

  function updateProgress(){
    const progress=document.getElementById('qabProgress');
    if(!progress||!audio||!Number.isFinite(audio.duration)||audio.duration<=0) return;
    progress.value=Math.min(100,(audio.currentTime/audio.duration)*100);
  }

  function renderBar(){
    let bar=document.getElementById('qAudioBar');
    if(!queue.length && !wantsPlayback){ if(bar) bar.remove(); return; }

    if(!bar){
      bar=document.createElement('div');
      bar.id='qAudioBar';
      bar.className='q-audio-bar';
      bar.innerHTML=`
        <span class="qab-ic"><i data-icon="mosque"></i></span>
        <div class="qab-main">
          <span class="qab-txt">تلاوة صفحة <b id="qabPage"></b></span>
          <progress id="qabProgress" max="100" value="0"></progress>
        </div>
        <button class="qab-btn" id="qabToggle" type="button" aria-label="تشغيل أو إيقاف"></button>
        <button class="qab-btn" id="qabClose" type="button" aria-label="إغلاق">✕</button>`;
      document.body.appendChild(bar);
      bar.querySelector('#qabToggle').onclick=()=>playing?pause():resume();
      bar.querySelector('#qabClose').onclick=stop;
    }

    const pg=bar.querySelector('#qabPage'); if(pg) pg.textContent=page||'—';
    const tg=bar.querySelector('#qabToggle'); if(tg) tg.textContent=playing?'⏸':'▶';
    bar.classList.toggle('is-playing',playing);
    updateProgress();
  }

  window.addEventListener('DOMContentLoaded',restore);
  window.addEventListener('pagehide',()=>save(true));
  window.addEventListener('beforeunload',()=>save(true));
  document.addEventListener('visibilitychange',()=>{ if(document.hidden) save(true); });

  return {
    playPage,
    pause,
    resume,
    stop,
    isPlaying:()=>playing,
    currentPage:()=>page
  };
})();


(function(){
  function render(){
    const slot=document.getElementById('authSlot');
    if(!slot || typeof PTCAuth==='undefined') return;
    const u=PTCAuth.user, p=PTCAuth.profile;
    if(!u){
      slot.innerHTML=`<a class="auth-link" href="login.html" title="تسجيل الدخول">
        <span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg></span>
        <span class="auth-label">دخول</span></a>`;
      return;
    }
    const name=(p&&p.full_name)||u.email.split('@')[0];
    const initial=(name||'؟').trim().charAt(0);
    const safeName=PTCUtils.escapeHTML(name);
    const safeInitial=PTCUtils.escapeHTML(initial);
    const safeEmail=PTCUtils.escapeHTML(u.email||'');
    const staff=PTCAuth.isStaff();
    slot.innerHTML=`
      <div class="tip-bell" id="tipBell">
        <button class="tip-bell-btn" type="button" aria-label="إشعارات">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          <span class="tip-bell-badge" id="tipBellBadge" style="display:none">0</span>
        </button>
        <div class="tip-bell-drop" id="tipBellDrop">
          <div class="tip-bell-head">
            <span>إشعارات</span>
            <div class="tip-bell-head-actions">
              <button type="button" class="tip-bell-mark" id="tipBellClear" title="تنظيف الكل">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              </button>
              <button type="button" class="tip-bell-mark" id="tipBellMark" title="تعليم الكل كمقروء">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              </button>
            </div>
          </div>
          <div class="tip-bell-list" id="tipBellList"><div class="tip-bell-empty">جارٍ التحميل...</div></div>
        </div>
      </div>
    <div class="user-menu" id="userMenu">
      <button class="user-btn" type="button" aria-label="حسابي"><span class="user-av">${safeInitial}</span></button>
      <div class="user-drop">
        <div class="ud-head"><b>${safeName}</b><span dir="ltr">${safeEmail}</span></div>
        ${p&&p.year?`<div class="ud-meta">السنة ${['','الأولى','الثانية','الثالثة','الرابعة'][p.year]||PTCUtils.escapeHTML(p.year)}</div>`:''}
        <a href="profile.html" class="ud-link">
          <span class="ud-ic ud-ic-profile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg></span>
          صفحتي وخطتي الدراسية
        </a>
        <a href="library.html" class="ud-link">
          <span class="ud-ic ud-ic-library"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
          مكتبتي
        </a>
        <a href="schedule.html" class="ud-link">
          <span class="ud-ic ud-ic-schedule"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
          جدولي الأسبوعي
        </a>
        <a href="gpa.html" class="ud-link">
          <span class="ud-ic ud-ic-gpa"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="20" x2="6" y2="14"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="10"/></svg></span>
          حاسبة المعدل
        </a>
        ${(!window.PTCPWAInstall || !window.PTCPWAInstall.isStandalone())
          ? `<button type="button" class="ud-link" onclick="window.PTCPWAInstall && window.PTCPWAInstall.triggerFromMenu()">
              <span class="ud-ic ud-ic-install"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>
              تثبيت التطبيق
            </button>`
          : ''}
        ${staff
          ? `<a href="admin.html" class="ud-link">
              <span class="ud-ic ud-ic-admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg></span>
              لوحة التحكم
            </a>`
          : `<a href="contact.html" class="ud-link">
              <span class="ud-ic ud-ic-contact"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg></span>
              تواصل معنا
            </a>`}
        <button type="button" class="ud-link" onclick="ptcSignOut()">
          <span class="ud-ic ud-ic-logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transform:scaleX(-1)"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
          تسجيل الخروج
        </button>
      </div>
    </div>`;
    const menu=slot.querySelector('#userMenu');
    menu.querySelector('.user-btn').onclick=e=>{
      e.stopPropagation();
      closeTipBellDrop();
      closeMobileNav();
      
      closeNavDrop();
      const wasOpen=menu.classList.contains('open');
      if(wasOpen){
        closeUserMenu();
      } else {
        menu.classList.add('open');
        UIBackStack.push('userMenu', ()=>{ menu.classList.remove('open'); });
      }
    };
    document.addEventListener('click',e=>{ if(!e.target.closest('#userMenu')) closeUserMenu(); });
    initTipBell();
  }

  
  let tipBellWired=false;
  let tipListLoaded=false;

  
  const DISMISSED_KEY='ptc_dismissed_notifications';

  
  function getDismissedMap(){
    try{
      const parsed=JSON.parse(localStorage.getItem(DISMISSED_KEY)||'{}');
      
      if(Array.isArray(parsed)) return {};
      return parsed&&typeof parsed==='object'?parsed:{};
    }
    catch(_){ return {}; }
  }
  function addDismissed(key){
    try{
      const m=getDismissedMap();
      m[key]=Date.now();
      localStorage.setItem(DISMISSED_KEY,JSON.stringify(m));
    }catch(_){}
  }
  function clearDismissed(){
    try{ localStorage.removeItem(DISMISSED_KEY); }catch(_){}
  }
  function itemKey(item){
    return `${item.type}:${item.payload?.id}`;
  }
  
  function isDismissed(item,dismissedMap){
    const at=dismissedMap[itemKey(item)];
    if(!at) return false;
    return at>=new Date(item.created_at).getTime();
  }

  let lastFetchedItems=[];
  let lastFetchedAt=0;
  const FRESH_MS=8000;

  async function fetchNotifications(forceFresh){
    
    if(!forceFresh && lastFetchedItems.length && (Date.now()-lastFetchedAt)<FRESH_MS){
      const dismissed=getDismissedMap();
      const unreadCount=lastFetchedItems.filter(item=>
        item.unread && !isDismissed(item,dismissed)
      ).length;
      return {items:lastFetchedItems,unreadCount};
    }
    const {data}=await PTCAuth.getRecentNotifications();
    lastFetchedItems=data;
    lastFetchedAt=Date.now();
    const dismissed=getDismissedMap();
    const unreadCount=data.filter(item=>
      item.unread && !isDismissed(item,dismissed)
    ).length;
    return {items:data,unreadCount};
  }

  function updateBadge(count){
    const badge=document.getElementById('tipBellBadge');
    if(!badge) return;
    if(count>0){ badge.textContent=count>99?'99+':String(count); badge.style.display='flex'; }
    else badge.style.display='none';
  }

  function decrementBadge(){
    const badge=document.getElementById('tipBellBadge');
    if(!badge || badge.style.display==='none') return;
    const current=Math.max(0,(parseInt(badge.textContent,10)||1)-1);
    updateBadge(current);
  }

  async function refreshTipBadge(forceFresh){
    try{
      const {unreadCount}=await fetchNotifications(forceFresh);
      updateBadge(unreadCount);
    }catch(e){  }
  }

  function timeAgo(iso){
    const then=new Date(iso).getTime();
    const diffMin=Math.max(1,Math.round((Date.now()-then)/60000));
    if(diffMin<60) return `منذ ${diffMin} د`;
    const diffH=Math.round(diffMin/60);
    if(diffH<24) return `منذ ${diffH} س`;
    return `منذ ${Math.round(diffH/24)} يوم`;
  }

  function authorName(u){
    return u?[u.first_name,u.father_name,u.last_name].filter(Boolean).join(' '):'(حساب محذوف)';
  }

  function bellRow({key,kindLabel,kindClass,course,title,message,meta,href,delHref}){
    const courseLine=course?`${PTCUtils.escapeHTML(course.code||'')} — ${PTCUtils.escapeHTML(course.name_ar||course.name_en||'')}`:'';
    return `
      <div class="tip-bell-item">
        <a class="tip-bell-item-body" href="${href}"${key?` data-notif-key="${PTCUtils.escapeHTML(key)}"`:''}>
          <div class="tip-bell-course"><span class="tip-bell-kind ${kindClass}">${kindLabel}</span> ${courseLine}</div>
          ${title?`<div class="tip-bell-title">${title}</div>`:''}
          <div class="tip-bell-message">${message}</div>
          <div class="tip-bell-meta">${meta}</div>
        </a>
        ${delHref?`<a class="tip-bell-del" href="${delHref}" title="مراجعة من لوحة التحكم">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        </a>`:''}
      </div>
    `;
  }

  function renderNotificationRow(item){
    const p=item.payload;
    const course=p.course||p.course_file?.course||p.courseFile?.course||null;
    const courseKey=PTCUtils.escapeHTML(course?.key||'');
    const key=itemKey(item);

    if(item.type==='tip'){
      return bellRow({
        key,kindLabel:'نصيحة',kindClass:'kind-tip',course,
        message:PTCUtils.escapeHTML(p.message||''),
        meta:`${PTCUtils.escapeHTML(authorName(p.user))} · ${timeAgo(p.created_at)}`,
        href:`course.html?course=${encodeURIComponent(courseKey)}&tip=${Number(p.id)}`,
        delHref:`admin.html?tab=news&tip=${Number(p.id)}&course=${encodeURIComponent(courseKey)}`
      });
    }

    if(item.type==='report'){
      const file=p.course_file||p.courseFile||null;
      const target=file?PTCUtils.escapeHTML(file.title||'محتوى محدد'):'عام على المادة';
      return bellRow({
        key:'',kindLabel:'بلاغ',kindClass:'kind-report',course,
        message:`<b>${PTCUtils.escapeHTML(p.reason_label||p.reason||'')}</b> — ${target}${p.note?`<br>${PTCUtils.escapeHTML(p.note)}`:''}`,
        meta:`${PTCUtils.escapeHTML(authorName(p.user))} · ${timeAgo(p.created_at)}`,
        href:`admin.html?tab=news&report=${Number(p.id)}&course=${encodeURIComponent(courseKey)}`,
        delHref:`admin.html?tab=news&report=${Number(p.id)}&course=${encodeURIComponent(courseKey)}`
      });
    }

    if(item.type==='content'){
      return bellRow({
        key,kindLabel:'محتوى جديد',kindClass:'kind-content',course,
        message:PTCUtils.escapeHTML(p.title||'محتوى'),
        meta:PTCAuth.isStaff()
          ?`نشره ${PTCUtils.escapeHTML(authorName(p.creator))} · ${timeAgo(p.created_at)}`
          :timeAgo(p.created_at),
        href:`course.html?course=${encodeURIComponent(courseKey)}&content=${Number(p.id)}`
      });
    }

    
    const reminderBadge=Number(p.reminder_count)>0
      ?` <span class="tip-bell-reminder"><i data-icon="bell" data-size="13"></i> تذكير ${Number(p.reminder_count)>1?Number(p.reminder_count):''}</span>`
      :'';
    return bellRow({
      key,kindLabel:'إعلان',kindClass:'kind-announcement',course,
      title:`${PTCUtils.escapeHTML(p.title||'')}${reminderBadge}`,
      message:PTCUtils.escapeHTML((p.body||'').slice(0,120)),
      meta:timeAgo(p.created_at),
      href:`index.html?announcement=${Number(p.id)}`
    });
  }

  async function loadTipDropdown(){
    const list=document.getElementById('tipBellList');
    if(!list) return;
    try{
      const {items}=await fetchNotifications();
      const dismissed=getDismissedMap();
      const visible=items.filter(item=>!isDismissed(item,dismissed));
      if(!visible.length){
        list.innerHTML='<div class="tip-bell-empty">ما في إشعارات جديدة حاليًا.</div>';
        return;
      }
      list.innerHTML=visible.map(renderNotificationRow).join('');
    }catch(e){
      list.innerHTML='<div class="tip-bell-empty">تعذّر تحميل الإشعارات.</div>';
    }
  }

  function initTipBell(){
    refreshTipBadge();
    if(tipBellWired) return;
    tipBellWired=true;

    
    setInterval(()=>{
      if(document.visibilityState==='visible'){
        refreshTipBadge(true);
        if(tipListLoaded){
          tipListLoaded=false;
          const drop=document.getElementById('tipBellDrop');
          if(drop&&drop.classList.contains('open')) loadTipDropdown().then(()=>{tipListLoaded=true;});
        }
      }
    },25000);

    document.addEventListener('click',async e=>{
      const clearBtn=e.target.closest('#tipBellClear');
      if(clearBtn){
        e.stopPropagation();
        
        lastFetchedItems
          .filter(item=>item.type!=='report')
          .forEach(item=>addDismissed(itemKey(item)));
        await refreshTipBadge(true);
        if(tipListLoaded) await loadTipDropdown();
        return;
      }

      const markBtn=e.target.closest('#tipBellMark');
      if(markBtn){
        e.stopPropagation();
        await PTCAuth.markNotificationsSeen().catch(()=>{});
        await refreshTipBadge(true);
        if(tipListLoaded) await loadTipDropdown();
        return;
      }

      
      const notifLink=e.target.closest('[data-notif-key]');
      if(notifLink){
        addDismissed(notifLink.dataset.notifKey);
        decrementBadge();
        notifLink.closest('.tip-bell-item')?.remove();
        return;
      }

      const btn=e.target.closest('.tip-bell-btn');
      const bell=document.getElementById('tipBell');
      if(btn){
        e.stopPropagation();
        const drop=document.getElementById('tipBellDrop');
        const opening=!drop.classList.contains('open');
        if(opening){
          closeUserMenu();
          closeMobileNav();
          
          
          
          closeNavDrop();
          drop.classList.add('open');
          UIBackStack.push('tipBellDrop', ()=>{ drop.classList.remove('open'); });
        } else {
          closeTipBellDrop();
        }
        if(opening && !tipListLoaded){
          tipListLoaded=true;
          await loadTipDropdown();
        }
        return;
      }
      if(bell && !e.target.closest('#tipBell')){
        closeTipBellDrop();
      }
    });
  }

  window.ptcSignOut=async function(){
    
    QuranAudio?.stop();
    await PTCAuth.signOut();
    location.replace('login.html');
  };
  window.addEventListener('ptc-auth-change',render);
  window.addEventListener('DOMContentLoaded',()=>setTimeout(render,400));
})();



const isLocalHost=['localhost','127.0.0.1','::1'].includes(location.hostname);
if('serviceWorker' in navigator && location.protocol!=='file:' && !isLocalHost){
  window.addEventListener('load',()=>navigator.serviceWorker.register('service-worker.js').catch(err=>console.warn('تعذّر تشغيل وضع PWA:',err)));
}


(function(){
  function banner(){
    let el=document.getElementById('offlineBanner');
    if(!el){
      el=document.createElement('div');
      el.id='offlineBanner';
      el.className='offline-banner';
      el.textContent='تعذّر الاتصال. تأكد من اتصالك بالإنترنت.';
      document.body.prepend(el);
    }
    return el;
  }
  let hideTimer=null;
  function show(){
    if(hideTimer){ clearTimeout(hideTimer); hideTimer=null; }
    banner().classList.add('show');
  }
  function hide(){
    const el=document.getElementById('offlineBanner');
    if(!el) return;
    
    hideTimer=setTimeout(()=>el.classList.remove('show'),400);
  }
  window.addEventListener('offline',show);
  window.addEventListener('online',hide);
  
  
  window.addEventListener('ptc-network-error',show);
  window.addEventListener('ptc-network-ok',hide);
  if(navigator.onLine===false) show();
})();


(function(){
  const actions=document.querySelector('.nav-actions');
  if(!actions) return;
  
  if(document.body.dataset.minimalNav) return;

  
  const btn=document.createElement('button');
  btn.className='theme-btn';
  btn.type='button';
  btn.setAttribute('aria-label','بحث بالموقع');
  btn.title='بحث بالموقع (اضغط /)';
  btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.4-4.4"/></svg>';
  btn.onclick=()=>openSiteSearch();
  actions.insertBefore(btn,actions.firstChild);

  let overlay,input,resultsBox,debounceTimer;
  let currentTerm='';

  
  const RECENT_KEY='ptc_recent_searches';
  function getRecentSearches(){
    try{ return JSON.parse(localStorage.getItem(RECENT_KEY)||'[]'); }
    catch(_){ return []; }
  }
  function addRecentSearch(term){
    if(!term||term.length<2) return;
    try{
      const list=getRecentSearches().filter(t=>t!==term);
      list.unshift(term);
      localStorage.setItem(RECENT_KEY,JSON.stringify(list.slice(0,5)));
    }catch(_){}
  }

  function ensureOverlay(){
    if(overlay) return;
    overlay=document.createElement('div');
    overlay.className='site-search-overlay';
    overlay.innerHTML=`
      <div class="site-search-box">
        <div class="site-search-input-row">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.4-4.4"/></svg>
          <input type="text" id="siteSearchInput" placeholder="ابحث عن مادة، محتوى، أو أداة...">
          <button type="button" class="site-search-close" aria-label="إغلاق">✕</button>
        </div>
        <div class="site-search-results" id="siteSearchResults"></div>
      </div>
    `;
    document.body.appendChild(overlay);
    input=overlay.querySelector('#siteSearchInput');
    resultsBox=overlay.querySelector('#siteSearchResults');

    overlay.addEventListener('click',e=>{ if(e.target===overlay) closeSiteSearch(); });
    overlay.querySelector('.site-search-close').onclick=closeSiteSearch;

    input.addEventListener('input',()=>{
      clearTimeout(debounceTimer);
      const term=input.value.trim();
      currentTerm=term;
      if(term.length<1){
        renderEmptyState();
        return;
      }
      resultsBox.innerHTML='<div class="site-search-hint">جارٍ البحث...</div>';
      debounceTimer=setTimeout(()=>runSearch(term),300);
    });

    input.addEventListener('keydown',handleResultsKeydown);
  }

  function renderEmptyState(){
    const recent=getRecentSearches();
    if(!recent.length){
      resultsBox.innerHTML='<div class="site-search-hint">اكتب لتبدأ البحث...</div>';
      return;
    }
    resultsBox.innerHTML=
      '<div class="site-search-group-label">عمليات بحث سابقة</div>'
      +recent.map(term=>`
        <button type="button" class="site-search-item site-search-recent" data-recent-term="${PTCUtils.escapeHTML(term)}">
          <span class="site-search-item-icon"><i data-icon="clock"></i></span>
          <div class="site-search-item-title">${PTCUtils.escapeHTML(term)}</div>
        </button>
      `).join('');
    resultsBox.querySelectorAll('[data-recent-term]').forEach(btn=>{
      btn.onclick=()=>{
        input.value=btn.dataset.recentTerm;
        input.dispatchEvent(new Event('input'));
      };
    });
  }

  const kindIcons={youtube:'<i data-icon="play"></i>',link:'<i data-icon="link"></i>',pdf:'<i data-icon="file"></i>',doc:'<i data-icon="file"></i>'};
  const toolTypeLabels={software:'برنامج',online:'أداة ويب',concept:'مفهوم',library:'مكتبة'};

  
  function highlightMatch(text,term){
    const safe=PTCUtils.escapeHTML(text||'');
    if(!term) return safe;
    const safeTerm=PTCUtils.escapeHTML(term).replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    return safe.replace(new RegExp(`(${safeTerm})`,'gi'),'<mark class="site-search-mark">$1</mark>');
  }

  async function runSearch(term){
    try{
      const response=await PTCApi.get(`/search?q=${encodeURIComponent(term)}`,{auth:false});
      renderResults(response.data||{},term);
      addRecentSearch(term);
    }catch(e){
      resultsBox.innerHTML='<div class="site-search-hint">تعذّر البحث، حاول مرة أخرى.</div>';
    }
  }

  function renderResults(data,term){
    const courses=data.courses||[];
    const content=data.content||[];
    const tools=data.tools||[];

    if(!courses.length && !content.length && !tools.length){
      resultsBox.innerHTML='<div class="site-search-hint">ما في نتائج مطابقة.</div>';
      return;
    }

    let html='';

    if(courses.length){
      html+='<div class="site-search-group-label">مواد</div>';
      html+=courses.map(c=>`
        <a class="site-search-item" href="course.html?course=${encodeURIComponent(c.key)}">
          <span class="site-search-item-icon"><i data-icon="bookOpen"></i></span>
          <div>
            <div class="site-search-item-title">${highlightMatch(c.name_ar||c.name_en||'',term)}</div>
            <div class="site-search-item-sub">${PTCUtils.escapeHTML(c.code||'')}</div>
          </div>
        </a>
      `).join('');
    }

    if(content.length){
      html+='<div class="site-search-group-label">محتوى</div>';
      html+=content.map(item=>{
        const course=item.course||{};
        return `
          <a class="site-search-item" href="course.html?course=${encodeURIComponent(course.key||'')}&content=${Number(item.id)}">
            <span class="site-search-item-icon">${kindIcons[item.kind]||'<i data-icon="paperclip"></i>'}</span>
            <div>
              <div class="site-search-item-title">${highlightMatch(item.title||'',term)}</div>
              <div class="site-search-item-sub">${PTCUtils.escapeHTML(course.name_ar||course.name_en||'')}</div>
            </div>
          </a>
        `;
      }).join('');
    }

    if(tools.length){
      html+='<div class="site-search-group-label">أدوات</div>';
      html+=tools.map(t=>`
        <a class="site-search-item" href="tools.html?tool=${Number(t.id)}">
          <span class="site-search-item-icon"><i data-icon="wrench"></i></span>
          <div>
            <div class="site-search-item-title">${highlightMatch(t.name||'',term)}</div>
            <div class="site-search-item-sub">${PTCUtils.escapeHTML(toolTypeLabels[t.type]||'')}</div>
          </div>
        </a>
      `).join('');
    }

    resultsBox.innerHTML=html;
  }

  
  function handleResultsKeydown(e){
    if(!['ArrowDown','ArrowUp','Enter'].includes(e.key)) return;

    const items=[...resultsBox.querySelectorAll('.site-search-item')];
    if(!items.length) return;

    e.preventDefault();

    let activeIndex=items.findIndex(el=>el.classList.contains('kbd-active'));

    if(e.key==='Enter'){
      const target=activeIndex>=0?items[activeIndex]:items[0];
      target.click();
      return;
    }

    items.forEach(el=>el.classList.remove('kbd-active'));

    if(e.key==='ArrowDown') activeIndex=(activeIndex+1)%items.length;
    else activeIndex=activeIndex<=0?items.length-1:activeIndex-1;

    items[activeIndex].classList.add('kbd-active');
    items[activeIndex].scrollIntoView({block:'nearest'});
  }

  
  const isCoarsePointer=window.matchMedia && window.matchMedia('(pointer:coarse)').matches;
  let searchHistoryPushed=false;

  window.openSiteSearch=function(){
    ensureOverlay();
    overlay.classList.add('open');
    input.value='';
    currentTerm='';
    renderEmptyState();
    if(!isCoarsePointer) setTimeout(()=>input.focus(),50);
    
    if(!searchHistoryPushed){
      history.pushState({siteSearchOpen:true},'');
      searchHistoryPushed=true;
    }
  };
  window.closeSiteSearch=function(){
    if(overlay) overlay.classList.remove('open');
    if(searchHistoryPushed){
      searchHistoryPushed=false;
      history.back();
    }
  };

  window.addEventListener('popstate',()=>{
    if(overlay && overlay.classList.contains('open')){
      searchHistoryPushed=false;
      overlay.classList.remove('open');
    }
  });

  
  document.addEventListener('keydown',e=>{
    if(e.key==='/' && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)){
      e.preventDefault();
      openSiteSearch();
    }
    if(e.key==='Escape' && overlay && overlay.classList.contains('open')){
      closeSiteSearch();
    }
  });
})();


(function () {
  if (typeof PTCAuth === 'undefined') return;

  const HISTORY_KEY = 'ptc_ai_chat_history';
  const CONV_ID_KEY = 'ptc_ai_conversation_id';
  const USED_BEFORE_KEY = 'ptc_ai_used_before';

  
  
  const GENERIC_STARTERS = [
    { text: 'ولّدلي ٣ أسئلة اختيار من متعدد على هذا الموضوع', icon: 'checkCircle' },
    { text: 'لخّصلي هاي الفكرة بخريطة ذهنية نصية (Mind Map)', icon: 'layers' },
    { text: 'اشرحلي هاد الموضوع متل ما بتشرحه لطالب سنة أولى', icon: 'bulb' },
  ];

  
  const PROGRAMMING_STARTERS = [
    { text: 'اشرحلي مفهوم تعدد الأشكال (Polymorphism) بمثال بسيط', icon: 'code' },
    { text: 'شو الأسباب الشائعة لخطأ NullReferenceException وكيف أصلّحه؟', icon: 'wrench' },
    { text: 'ولّدلي هيكل كود أساسي (Boilerplate) لتنفيذ فكرة معيّنة', icon: 'edit' },
  ];

  
  const SYSTEMS_STARTERS = [
    { text: 'قارنلي بين معماريتي RISC وCISC وأهم الفروقات العملية بينهم', icon: 'chart' },
    { text: 'اشرحلي خطوات الـ Handshaking بين بروتوكولين بشكل مبسّط', icon: 'link' },
    { text: 'ساعدني أحل مسألة تحويل أعداد بين الأنظمة العددية أو تتبّع دارة رقمية', icon: 'settings' },
  ];

  
  const PERSONAL_STARTERS = [
    { text: 'قيّم أدائي الدراسي وقلي شو لازمني أعمل لأرفع معدلي', icon: 'chart' },
    { text: 'اعملّي خطة مذاكرة لهاد الأسبوع حسب جدولي', icon: 'calendar' },
    { text: 'راجع لي حل هذا الواجب خطوة بخطوة وصحح الأخطاء إن وجدت', icon: 'camera', action: 'image' },
  ];

  
  const PROGRAMMING_KEYWORDS = [
    'برمج', 'خوارزم', 'هياكل بيانات', 'تراكيب بيانات', 'كائني', 'شيئي',
    'قواعد بيانات', 'ويب', 'تطبيق', 'تطبيقات', 'لارافيل', 'جافا', 'بايثون',
    'oop', 'c#', 'c++', 'java', 'python', 'laravel', 'sql', 'algorithm',
    'data structure', 'programming', 'software',
  ];
  const SYSTEMS_KEYWORDS = [
    'معماري', 'ميكروبروسيسور', 'معالج', 'شبك', 'دارات', 'دارة', 'رقمي',
    'إلكترون', 'الكترون', 'أنظمة تشغيل', 'حاسوب', 'اتصالات', 'إشارات',
    'architecture', 'microprocessor', 'network', 'circuit', 'digital logic',
    'signal', 'embedded', 'operating system',
  ];

  function detectCourseCategory(name, code) {
    const haystack = `${name || ''} ${code || ''}`.toLowerCase();
    if (!haystack.trim()) return null;
    if (PROGRAMMING_KEYWORDS.some(k => haystack.includes(k))) return 'programming';
    if (SYSTEMS_KEYWORDS.some(k => haystack.includes(k))) return 'systems';
    return null;
  }

  function buildStarters() {
    const course = currentCourseName();
    const category = detectCourseCategory(course, currentCourseCode());

    let head;
    if (category === 'programming') head = PROGRAMMING_STARTERS;
    else if (category === 'systems') head = SYSTEMS_STARTERS;
    else head = GENERIC_STARTERS;

    return [...head, ...PERSONAL_STARTERS];
  }

  let panelOpen = false;
  let sending = false;
  let mounted = false;

  function getHistory() {
    try { return JSON.parse(sessionStorage.getItem(HISTORY_KEY) || '[]'); }
    catch (_) { return []; }
  }

  function saveHistory(history) {
    try { sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(-20))); }
    catch (_) {}
  }

  function getConvId() {
    try { return sessionStorage.getItem(CONV_ID_KEY) || null; }
    catch (_) { return null; }
  }

  function setConvId(id) {
    try { sessionStorage.setItem(CONV_ID_KEY, String(id)); }
    catch (_) {}
  }

  function clearConvId() {
    try { sessionStorage.removeItem(CONV_ID_KEY); }
    catch (_) {}
  }

  function currentCourseName() {
    return window.PTCCoursePage?.getCurrentCourseName?.() || null;
  }

  function currentCourseCode() {
    return window.PTCCoursePage?.getCurrentCourseCode?.() || null;
  }

  function escapeHTML(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function mount() {
    if (mounted || !PTCAuth.user) return;
    mounted = true;

    
    const AI_ICON = '<svg viewBox="0 0 24 24" fill="none"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25L19 9zM11.5 9.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12l-5.5-2.5z" fill="currentColor"/></svg>';

    const wrapper = document.createElement('div');
    wrapper.className = 'ai-fab-wrap';
    wrapper.setAttribute('role', 'button');
    wrapper.setAttribute('tabindex', '0');
    wrapper.setAttribute('aria-label', 'PtcHub AI — مساعد المذاكرة الذكي');


    const circleWrap = document.createElement('span');
    circleWrap.className = 'ai-fab-circle';

    const glow = document.createElement('span');
    glow.className = 'ai-fab-glow';
    glow.setAttribute('aria-hidden', 'true');
    circleWrap.appendChild(glow);

    const button = document.createElement('button');
    button.className = 'ai-fab';
    button.type = 'button';
    button.tabIndex = -1;
    button.setAttribute('aria-hidden', 'true');
    button.innerHTML = AI_ICON;
    circleWrap.appendChild(button);

    const onlineDot = document.createElement('span');
    onlineDot.className = 'ai-fab-online';
    onlineDot.setAttribute('aria-hidden', 'true');
    onlineDot.title = 'المساعد جاهز الآن';
    button.appendChild(onlineDot);

    wrapper.appendChild(circleWrap);

    const label = document.createElement('span');
    label.className = 'ai-fab-label';
    label.textContent = 'اسأل PtcHub AI';
    wrapper.appendChild(label);

    const tooltip = document.createElement('div');
    tooltip.className = 'ai-fab-tooltip';
    tooltip.setAttribute('aria-hidden', 'true');
    tooltip.innerHTML = `
      <span class="ai-fab-tooltip-title">مساعدك الأكاديمي جاهز ✨</span>
      <span class="ai-fab-tooltip-sub">مرحباً بك! جاهز لمساعدتك في خطتك الدراسية، متطلباتك العلمية، وحساب معدلك ومساقاتك ✨</span>
    `;
    wrapper.appendChild(tooltip);

    if (!localStorage.getItem(USED_BEFORE_KEY)) {
      circleWrap.classList.add('ai-fab-invite');
    }

    document.body.appendChild(wrapper);

    const panel = document.createElement('div');
    panel.className = 'ai-panel';
    panel.innerHTML = `
      <div class="ai-panel-head">
        <span class="ai-panel-title">
          ${AI_ICON}
          PtcHub AI
        </span>
        <div class="ai-panel-head-actions">
          <button type="button" class="ai-panel-minimize" title="إخفاء مؤقت (الرد يستمر بالخلفية)" aria-label="إخفاء مؤقت">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
          </button>
          <button type="button" class="ai-panel-reload" title="إعادة توليد آخر رد" aria-label="إعادة توليد آخر رد">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          </button>
          <button type="button" class="ai-panel-history" title="سجلّ المحادثات" aria-label="سجلّ المحادثات">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h16"/><circle cx="19" cy="12" r="2"/></svg>
          </button>
          <button type="button" class="ai-panel-expand" title="تكبير الشاشة" aria-label="تكبير الشاشة">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/></svg>
          </button>
          <button type="button" class="ai-panel-new" title="محادثة جديدة" aria-label="محادثة جديدة">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          </button>
          <button type="button" class="ai-panel-close" aria-label="إغلاق">✕</button>
        </div>
      </div>
      <div class="ai-course-badge" id="aiCourseBadge" style="display:none"></div>
      <div class="ai-usage-counter" id="aiUsageCounter" style="display:none"></div>
      <button type="button" class="ai-history-back" id="aiHistoryBack" style="display:none">⇦ رجوع للمحادثة</button>
      <div class="ai-history-list" id="aiHistoryList" style="display:none"></div>
      <div class="ai-messages" id="aiMessages"></div>
      <div class="ai-attachment-preview" id="aiAttachmentPreview" aria-live="polite"></div>
      <div class="ai-input-row">
        <input type="file" id="aiFileInput" hidden accept="image/jpeg,image/png,image/webp,image/gif,image/bmp">
        <input type="file" id="aiFileInputDocs" hidden accept="application/pdf,text/plain,text/csv,text/markdown,application/json">
        <div class="ai-attach-wrap">
          <button type="button" class="ai-attach" id="aiAttachBtn" aria-label="إرفاق ملف" title="إرفاق ملف">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.19 9.19a2 2 0 0 1-2.83-2.83l8.48-8.49"/></svg>
          </button>
          <div class="ai-attach-menu" id="aiAttachMenu" hidden>
            <button type="button" class="ai-attach-menu-item" id="aiAttachDocBtn"><i data-icon="file"></i> ملف (PDF أو نص)</button>
            <button type="button" class="ai-attach-menu-item" id="aiAttachImgBtn"><i data-icon="camera"></i> صورة</button>
          </div>
        </div>
        <input type="text" id="aiInput" placeholder="اسأل عن أي موضوع دراسي..." autocomplete="off">
        <button type="button" class="ai-send" id="aiSendBtn" aria-label="إرسال">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>
        </button>
      </div>
    `;
    document.body.appendChild(panel);

    const messagesBox = panel.querySelector('#aiMessages');
    
    messagesBox.addEventListener('click', e => {
      const card = e.target.closest('.ai-flashcard');
      if (card) card.classList.toggle('is-flipped');
    });
    const input = panel.querySelector('#aiInput');
    const sendBtn = panel.querySelector('#aiSendBtn');
    const fileInput = panel.querySelector('#aiFileInput');
    const fileInputDocs = panel.querySelector('#aiFileInputDocs');
    const attachBtn = panel.querySelector('#aiAttachBtn');
    const attachMenu = panel.querySelector('#aiAttachMenu');
    const attachmentPreview = panel.querySelector('#aiAttachmentPreview');
    const courseBadge = panel.querySelector('#aiCourseBadge');
    const usageCounter = panel.querySelector('#aiUsageCounter');
    let pendingAttachments = [];
    
    let turnToken = 0;

    
    function toArabicDigits(n) {
      return String(n).replace(/[0-9]/g, d => '٠١٢٣٤٥٦٧٨٩'[d]);
    }

    async function refreshUsageCounter() {
      try {
        const usage = await PTCAuth.getAiUsage();
        const remaining = Number.isFinite(Number(usage.remaining))
          ? Number(usage.remaining)
          : (Number(usage.limit) - Number(usage.used));
        const isLow = remaining > 0 && remaining <= 5;

        usageCounter.textContent = isLow
          ? `${toArabicDigits(usage.used)} من ${toArabicDigits(usage.limit)} اليوم — تبقّى لك ${toArabicDigits(remaining)} بس اليوم`
          : `${toArabicDigits(usage.used)} من ${toArabicDigits(usage.limit)} اليوم`;
        usageCounter.classList.toggle('ai-usage-counter-warn', isLow);
        usageCounter.style.display = '';
      } catch (error) {
        
      }
    }

    
    function renderMarkdownLite(text) {
      const safe = escapeHTML(text);
      const withBold = safe.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
      const lines = withBold.split('\n');
      let html = '';
      let inList = false;

      lines.forEach(line => {
        const bullet = line.match(/^[-*]\s+(.*)/);
        if (bullet) {
          if (!inList) { html += '<ul>'; inList = true; }
          html += `<li>${bullet[1]}</li>`;
        } else {
          if (inList) { html += '</ul>'; inList = false; }
          html += line ? `<p>${line}</p>` : '';
        }
      });
      if (inList) html += '</ul>';

      return html;
    }

    
    function parseFlashcards(text) {
      if (!text) return null;

      const chunks = text.split(/\n\s*-{3,}\s*\n?/).map(c => c.trim()).filter(Boolean);
      const cards = [];

      chunks.forEach(chunk => {
        const qMatch = chunk.match(/س[:：]\s*([\s\S]*?)(?:\n\s*ج[:：]|$)/);
        const aMatch = chunk.match(/ج[:：]\s*([\s\S]*)$/);
        if (!qMatch || !aMatch) return;

        const q = qMatch[1].trim();
        const a = aMatch[1].trim();
        if (q && a) cards.push({ q, a });
      });

      
      return cards.length >= 1 ? cards : null;
    }

    function renderFlashcardsHTML(cards) {
      return `
        <div class="ai-flashcards-wrap">
          <div class="ai-flashcards-hint">دوس على أي بطاقة لتشوف الجواب (${toArabicDigits(cards.length)} بطاقة)</div>
          <div class="ai-flashcards">
            ${cards.map((c, i) => `
              <button type="button" class="ai-flashcard">
                <span class="ai-flashcard-inner">
                  <span class="ai-flashcard-face ai-flashcard-front">
                    <span class="ai-flashcard-num">${toArabicDigits(i + 1)}</span>
                    <span>${escapeHTML(c.q)}</span>
                  </span>
                  <span class="ai-flashcard-face ai-flashcard-back">
                    <span>${escapeHTML(c.a)}</span>
                  </span>
                </span>
              </button>
            `).join('')}
          </div>
        </div>
      `;
    }

    
    function renderAssistantContent(text) {
      const cards = parseFlashcards(text);
      return cards ? renderFlashcardsHTML(cards) : renderMarkdownLite(text);
    }

    function renderMessage(role, text, index, feedback, attachments = []) {
      const row = document.createElement('div');
      row.className = 'ai-msg ai-msg-' + role;

      const toolbar = role === 'assistant' ? `
        <div class="ai-msg-tools">
          <button type="button" class="ai-msg-tool ai-msg-regen" title="إعادة توليد هذا الرد" aria-label="إعادة توليد">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          </button>
          <button type="button" class="ai-msg-tool ai-msg-up${feedback === 'up' ? ' active' : ''}" title="إجابة مفيدة" aria-label="إعجاب">
            <svg viewBox="0 0 24 24" fill="${feedback === 'up' ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12M15 5.88 14 10h6.29a2 2 0 0 1 1.94 2.5l-2.34 9A2 2 0 0 1 18 23H4a2 2 0 0 1-2-2V12a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a2.7 2.7 0 0 1 3 3z"/></svg>
          </button>
          <button type="button" class="ai-msg-tool ai-msg-down${feedback === 'down' ? ' active' : ''}" title="إجابة غير مفيدة" aria-label="عدم إعجاب">
            <svg viewBox="0 0 24 24" fill="${feedback === 'down' ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 14V2M9 18.12 10 14H3.71a2 2 0 0 1-1.94-2.5l2.34-9A2 2 0 0 1 6 1h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a2.7 2.7 0 0 1-3-3z"/></svg>
          </button>
          <button type="button" class="ai-msg-tool ai-msg-copy" title="نسخ" aria-label="نسخ الرد">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
          </button>
        </div>
      ` : '';

      const attachmentHtml = Array.isArray(attachments) && attachments.length
        ? `<div class="ai-msg-attachments">${attachments.map(a => `
            <div class="ai-msg-attachment">
              <span class="ai-msg-attachment-icon">${a.mime_type?.startsWith('image/') ? '<i data-icon="image"></i>' : '<i data-icon="file"></i>'}</span>
              <span class="ai-msg-attachment-name">${escapeHTML(a.name || 'ملف مرفق')}</span>
            </div>`).join('')}
          </div>`
        : '';
      row.innerHTML = `${attachmentHtml}<div class="ai-msg-bubble">${role === 'assistant' ? renderAssistantContent(text || '') : escapeHTML(text || '')}</div>${toolbar}`;
      messagesBox.appendChild(row);
      messagesBox.scrollTop = messagesBox.scrollHeight;

      if (role === 'assistant') {
        row.querySelector('.ai-msg-copy')?.addEventListener('click', () => {
          navigator.clipboard?.writeText(row.querySelector('.ai-msg-bubble').textContent);
        });

        row.querySelector('.ai-msg-regen')?.addEventListener('click', () => regenerateAt(index));

        row.querySelectorAll('.ai-msg-up, .ai-msg-down').forEach(btn => {
          btn.addEventListener('click', () => sendFeedback(row, index, btn.classList.contains('ai-msg-up') ? 'up' : 'down'));
        });
      }

      return row;
    }

    async function sendFeedback(row, index, value) {
      const convId = getConvId();
      if (convId == null || index == null) return;

      try {
        const result = await PTCAuth.sendAiFeedback(convId, index, value);
        row.querySelector('.ai-msg-up')?.classList.toggle('active', result.feedback === 'up');
        row.querySelector('.ai-msg-down')?.classList.toggle('active', result.feedback === 'down');
        row.querySelector('.ai-msg-up svg')?.setAttribute('fill', result.feedback === 'up' ? 'currentColor' : 'none');
        row.querySelector('.ai-msg-down svg')?.setAttribute('fill', result.feedback === 'down' ? 'currentColor' : 'none');
      } catch (_) {
        
      }
    }

    function renderStarters() {
      const starters = buildStarters();
      const box = document.createElement('div');
      box.className = 'ai-starters';
      box.innerHTML = starters.map(s => `
        <button type="button" class="ai-starter-chip">
          <span class="ai-starter-icon"><i data-icon="${s.icon || 'bulb'}"></i></span>
          <span>${escapeHTML(s.text)}</span>
        </button>
      `).join('');
      box.querySelectorAll('.ai-starter-chip').forEach((chip, i) => {
        const starter = starters[i];
        chip.onclick = () => {
          input.value = starter.text;
          
          if (starter.action === 'image') fileInput.click();
          input.focus();
        };
      });
      messagesBox.appendChild(box);
    }

    
    function greetingPrefix() {
      const hour = new Date().getHours();
      const phrase = hour < 11 ? 'صباحًا سعيدًا' : hour < 15 ? 'نهارًا سعيدًا' : 'مساءً سعيدًا';
      const firstName = PTCAuth.user?.first_name;
      return firstName ? `${phrase} يا ${firstName}` : phrase;
    }

    function renderEmptyState() {
      messagesBox.innerHTML = `
        <div class="ai-empty">
          <p class="ai-greeting">${escapeHTML(greetingPrefix())}</p>
          <p>أهلًا! أنا مساعدك بالمذاكرة — اسألني عن أي مفهوم برمجي أو هندسي، أو اطلب تلخيصًا أو سؤال اختبار.</p>
        </div>
      `;
      renderStarters();
    }

    function refreshCourseBadge() {
      const name = currentCourseName();
      if (name) {
        courseBadge.innerHTML = `<i data-icon="bookOpen"></i> على دراية بمادة: ${escapeHTML(name)}`;
        courseBadge.style.display = '';
      } else {
        courseBadge.style.display = 'none';
      }
    }

    function loadHistoryIntoPanel() {
      const history = getHistory();
      messagesBox.innerHTML = '';

      if (!history.length) {
        renderEmptyState();
        return;
      }

      history.forEach((m, i) => renderMessage(m.role, m.text, i, m.feedback, m.attachments));
    }

    const historyListBox = panel.querySelector('#aiHistoryList');
    const historyBackBtn = panel.querySelector('#aiHistoryBack');
    let historyListOpen = false;

    function hideHistoryList() {
      historyListOpen = false;
      historyListBox.style.display = 'none';
      historyBackBtn.style.display = 'none';
      messagesBox.style.display = '';
      panel.querySelector('.ai-input-row').style.display = '';
    }

    async function toggleHistoryList() {
      if (historyListOpen) {
        hideHistoryList();
        return;
      }

      historyListOpen = true;
      messagesBox.style.display = 'none';
      panel.querySelector('.ai-input-row').style.display = 'none';
      historyListBox.style.display = 'block';
      historyBackBtn.style.display = 'block';
      historyListBox.innerHTML = '<div class="ai-empty">جارٍ التحميل...</div>';

      let conversations;
      try {
        conversations = await PTCAuth.getAiConversations();
      } catch (_) {
        historyListBox.innerHTML = '<div class="ai-empty">تعذّر تحميل السجلّ.</div>';
        return;
      }

      if (!conversations.length) {
        historyListBox.innerHTML = '<div class="ai-empty">ما في محادثات سابقة بعد.</div>';
        return;
      }

      historyListBox.innerHTML = conversations.map(c => `
        <button type="button" class="ai-history-item" data-conv-id="${c.id}">
          <span class="ai-history-item-title">${escapeHTML(c.title || 'محادثة')}</span>
        </button>
      `).join('');

      historyListBox.querySelectorAll('[data-conv-id]').forEach(btn => {
        btn.onclick = () => loadConversation(Number(btn.dataset.convId));
      });
    }

    async function loadConversation(id) {
      historyListBox.innerHTML = '<div class="ai-empty">جارٍ التحميل...</div>';

      let conversation;
      try {
        conversation = await PTCAuth.getAiConversation(id);
      } catch (error) {
        console.error('PtcHub AI — تعذّر جلب المحادثة:', error);
        historyListBox.innerHTML = '<div class="ai-empty">تعذّر تحميل هذه المحادثة.</div>';
        return;
      }

      const rawMessages = Array.isArray(conversation?.messages) ? conversation.messages : [];

      if (!rawMessages.length) {
        console.warn('PtcHub AI — المحادثة رجعت بلا رسائل:', conversation);
        historyListBox.innerHTML = '<div class="ai-empty">هذه المحادثة فارغة أو تعذّر عرضها.</div>';
        return;
      }

      const history = rawMessages.map(m => ({ role: m.role, text: m.text || '', feedback: m.feedback || null, attachments: Array.isArray(m.attachments) ? m.attachments : [] }));
      saveHistory(history);
      setConvId(conversation.id);

      hideHistoryList();
      messagesBox.innerHTML = '';
      history.forEach((m, i) => renderMessage(m.role, m.text, i, m.feedback, m.attachments));
      messagesBox.scrollTop = messagesBox.scrollHeight;
    }

    function formatFileSize(bytes) {
      if (bytes < 1024) return `${bytes} B`;
      if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
      return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    
    function renderPendingAttachments() {
      const list = pendingAttachments.map((a, i) => `
        <div class="ai-pending-attachment ${a.uploading ? 'is-uploading' : ''}">
          <span class="ai-pending-attachment-icon">${a.mime_type?.startsWith('image/') ? '<i data-icon="image"></i>' : '<i data-icon="file"></i>'}</span>
          <span class="ai-pending-attachment-info">
            <b>${escapeHTML(a.name)}</b>
            <small>${a.uploading ? 'جارٍ الرفع…' : formatFileSize(a.size_bytes || 0)}</small>
          </span>
          ${a.uploading ? '<span class="ai-pending-spinner"></span>' : ''}
          <button type="button" class="ai-pending-remove" data-index="${i}" data-cancel="${a.uploading ? '1' : '0'}" aria-label="${a.uploading ? 'إلغاء الرفع' : 'إزالة الملف'}">×</button>
        </div>
      `).join('');

      const errorMessage = attachmentPreview.dataset.error;
      const errorHtml = errorMessage
        ? `<div class="ai-attachment-error" role="alert">${escapeHTML(errorMessage)}</div>`
        : '';

      attachmentPreview.innerHTML = list + errorHtml;

      attachmentPreview.querySelectorAll('.ai-pending-remove').forEach(btn => {
        btn.onclick = () => {
          const idx = Number(btn.dataset.index);
          const item = pendingAttachments[idx];

          
          if (btn.dataset.cancel === '1') {
            item?.controller?.abort();
            return;
          }

          const [removed] = pendingAttachments.splice(idx, 1);
          renderPendingAttachments();
          
          if (removed?.id) {
            PTCAuth.deleteAiAttachment(removed.id).catch(() => {});
          }
        };
      });
    }

    function showAttachmentError(message) {
      attachmentPreview.dataset.error = message || 'تعذّر رفع الملف.';
      renderPendingAttachments();
    }

    function clearAttachmentError() {
      if (!attachmentPreview.dataset.error) return;
      delete attachmentPreview.dataset.error;
      renderPendingAttachments();
    }

    async function handleFiles(fileList, sourceInput) {
      const files = Array.from(fileList || []);
      if (!files.length) return;

      clearAttachmentError();

      if (pendingAttachments.length + files.length > 4) {
        showAttachmentError('يمكنك إرفاق 4 ملفات كحد أقصى مع السؤال الواحد.');
        if (sourceInput) sourceInput.value = '';
        return;
      }

      const allowed = new Set([
        'application/pdf', 'application/json', 'text/plain', 'text/csv', 'text/markdown',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'
      ]);
      const maxSize = 50 * 1024 * 1024;

      for (const file of files) {
        if (!allowed.has(file.type)) {
          showAttachmentError(`الملف «${file.name}» غير مدعوم. المسموح حاليًا PDF والصور وملفات النص وCSV وJSON.`);
          continue;
        }
        if (file.size > maxSize) {
          showAttachmentError(`الملف «${file.name}» أكبر من الحد المسموح (50 MB).`);
          continue;
        }

        const controller = new AbortController();
        const local = { name: file.name, mime_type: file.type, size_bytes: file.size, uploading: true, controller };
        pendingAttachments.push(local);
        renderPendingAttachments();

        try {
          const uploaded = await PTCAuth.startAiAttachmentUpload(file, controller.signal);
          delete local.controller;
          Object.assign(local, uploaded, { uploading: false });
          clearAttachmentError();
          renderPendingAttachments();
        } catch (error) {
          const idx = pendingAttachments.indexOf(local);
          if (idx !== -1) pendingAttachments.splice(idx, 1);
          renderPendingAttachments();
          
          if (!error?.isCancelled) {
            showAttachmentError(error?.message || `تعذّر رفع «${file.name}».`);
          }
        }
      }

      if (sourceInput) sourceInput.value = '';
    }

    function showTyping(label) {
      const row = document.createElement('div');
      row.className = 'ai-msg ai-msg-assistant ai-typing-row';
      const labelHtml = `<span class="ai-typing-label">${escapeHTML(label || 'جارٍ التفكير')}</span>`;
      row.innerHTML = `<div class="ai-msg-bubble ai-typing">${labelHtml}<span></span><span></span><span></span></div>`;
      messagesBox.appendChild(row);
      messagesBox.scrollTop = messagesBox.scrollHeight;
      return row;
    }

    
    let typingTimer = null;
    let awaitingReply = false;

    let revealPos = 0;
    let revealText = '';
    let revealPaused = false;
    let revealIsCards = false;

    function typewriterReveal(bubbleEl, fullText, onDone) {
      revealPos = 0;
      revealText = fullText;
      revealPaused = false;
      bubbleEl.textContent = '';

      
      revealIsCards = !!parseFlashcards(fullText);

      if (revealIsCards) {
        revealCardsAfterDelay(bubbleEl, onDone);
        return;
      }

      runReveal(bubbleEl, onDone);
    }

    function revealCardsAfterDelay(bubbleEl, onDone) {
      bubbleEl.classList.add('ai-cards-loading');
      typingTimer = setTimeout(() => {
        typingTimer = null;
        bubbleEl.classList.remove('ai-cards-loading');
        bubbleEl.innerHTML = renderAssistantContent(revealText);
        onDone();
      }, 450);
    }

    function runReveal(bubbleEl, onDone) {
      typingTimer = setInterval(() => {
        revealPos += 2;
        bubbleEl.textContent = revealText.slice(0, revealPos);
        messagesBox.scrollTop = messagesBox.scrollHeight;

        if (revealPos >= revealText.length) {
          clearInterval(typingTimer);
          typingTimer = null;
          bubbleEl.innerHTML = renderAssistantContent(revealText);
          onDone();
        }
      }, 14);
    }

    
    function pauseReveal() {
      if (!typingTimer) return;
      clearInterval(typingTimer);
      typingTimer = null;
      revealPaused = true;
      sendBtn.classList.add('is-paused');
      sendBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 4l14 8-14 8z"/></svg>';
    }

    function resumeReveal(bubbleEl, onDone) {
      revealPaused = false;
      sendBtn.classList.remove('is-paused');
      sendBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';

      if (revealIsCards) {
        revealCardsAfterDelay(bubbleEl, onDone);
        return;
      }

      runReveal(bubbleEl, onDone);
    }

    function setSendingState(isSending) {
      sending = isSending;
      sendBtn.classList.toggle('is-stop', isSending);
      sendBtn.innerHTML = isSending
        ? '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg>';
      input.disabled = isSending;
      attachBtn.disabled = isSending;
    }

    const POLL_INTERVAL_MS = 5000;
    
    const MAX_POLL_ATTEMPTS = 36; 
    const MAX_POLL_ATTEMPTS_FILE = 240; 
    const PENDING_KEY = 'ptc_ai_pending_question';

    function wait(ms) {
      return new Promise(resolve => setTimeout(resolve, ms));
    }

    function savePending(id, userText, typingLabel, isFileQuestion) {
      try { sessionStorage.setItem(PENDING_KEY, JSON.stringify({ id, userText, typingLabel, isFileQuestion: !!isFileQuestion })); }
      catch (_) {}
    }

    function getPending() {
      try { return JSON.parse(sessionStorage.getItem(PENDING_KEY) || 'null'); }
      catch (_) { return null; }
    }

    function clearPending() {
      try { sessionStorage.removeItem(PENDING_KEY); }
      catch (_) {}
    }

    function showBadge() {
      button.classList.add('ai-fab-badge');
    }

    function clearBadge() {
      button.classList.remove('ai-fab-badge');
    }

    let notifyPermissionAsked = false;

    function notifyReady(text) {
      if (document.hasFocus() && panelOpen) return; 

      showBadge();

      if (!('Notification' in window)) return;

      if (Notification.permission === 'granted') {
        new Notification('PtcHub AI — الرد جاهز', { body: text.slice(0, 120), icon: 'logo.png' });
      } else if (Notification.permission === 'default' && !notifyPermissionAsked) {
        notifyPermissionAsked = true;
        Notification.requestPermission();
      }
    }

    async function pollUntilDone(questionId, onStage, isFileQuestion) {
      const maxAttempts = isFileQuestion ? MAX_POLL_ATTEMPTS_FILE : MAX_POLL_ATTEMPTS;
      for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await wait(POLL_INTERVAL_MS);

        let statusResult;
        try {
          statusResult = await PTCAuth.pollAssistantResult(questionId);
        } catch (_) {
          continue; 
        }

        if (statusResult.status === 'done') {
          return { reply: statusResult.reply };
        }
        if (statusResult.status === 'failed') {
          return { errorMsg: statusResult.message || 'صار في خطأ، حاول مرة أخرى.' };
        }
        
        if (statusResult.stage && onStage) onStage(statusResult.stage);
      }

      return { errorMsg: 'الرد تأخر أكثر من المتوقع — جرّب مرة أخرى بعد قليل.' };
    }

    async function runTurn(userText, history, questionId, typingLabel, existingTypingRow, isFileQuestion) {
      const myTurnToken = turnToken;

      setSendingState(true);
      awaitingReply = true;

      
      let typingRow = existingTypingRow || (panelOpen ? showTyping(typingLabel) : null);
      if (existingTypingRow && typingLabel) {
        const labelEl = existingTypingRow.querySelector('.ai-typing-label');
        if (labelEl) labelEl.textContent = typingLabel;
      }

      const outcome = await pollUntilDone(questionId, stage => {
        const labelEl = typingRow?.querySelector('.ai-typing-label');
        if (labelEl) labelEl.textContent = stage;
      }, isFileQuestion);
      clearPending();
      awaitingReply = false;

      typingRow?.remove();

      if (myTurnToken !== turnToken) {
        
        return;
      }

      const finalText = outcome.errorMsg || outcome.reply;

      if (!panelOpen) {
        
        if (!outcome.errorMsg) {
          history.push({ role: 'assistant', text: finalText });
          saveHistory(history);
        }
        notifyReady(finalText);
        setSendingState(false);
        return;
      }

      const bubbleRow = renderMessage('assistant', '', history.length);
      const bubbleEl = bubbleRow.querySelector('.ai-msg-bubble');

      
      sendBtn.onclick = () => {
        if (revealPaused) resumeReveal(bubbleEl, finishTurn);
        else pauseReveal();
      };

      function finishTurn() {
        if (!outcome.errorMsg) {
          history.push({ role: 'assistant', text: finalText });
          saveHistory(history);
        }
        setSendingState(false);
        sendBtn.onclick = send;
        input.focus();
        refreshUsageCounter();
      }

      typewriterReveal(bubbleEl, finalText, finishTurn);
    }

    
    async function handleQueuedResponse(queued, history, userText, typingLabel, existingTypingRow, isFileQuestion) {
      setConvId(queued.conversation_id);

      if (queued.reply) {
        existingTypingRow?.remove();
        setSendingState(true);
        const bubbleRow = renderMessage('assistant', '', history.length);
        const bubbleEl = bubbleRow.querySelector('.ai-msg-bubble');

        sendBtn.onclick = () => {
          if (revealPaused) resumeReveal(bubbleEl, finishCached);
          else pauseReveal();
        };

        function finishCached() {
          history.push({ role: 'assistant', text: queued.reply, attachments: [] });
          saveHistory(history);
          setSendingState(false);
          sendBtn.onclick = send;
          input.focus();
          refreshUsageCounter();
        }

        typewriterReveal(bubbleEl, queued.reply, finishCached);
        return;
      }

      savePending(queued.question_id, userText, typingLabel, isFileQuestion);
      await runTurn(userText, history, queued.question_id, typingLabel, existingTypingRow, isFileQuestion);
    }

    async function send() {
      const text = input.value.trim();
      if ((!text && !pendingAttachments.length) || sending) return;
      if (pendingAttachments.some(a => a.uploading)) {
        showAttachmentError('انتظر حتى يكتمل رفع الملفات قبل الإرسال.');
        return;
      }

      localStorage.setItem(USED_BEFORE_KEY, '1');
      button.classList.remove('ai-fab-invite');

      const history = getHistory();
      if (!history.length) messagesBox.innerHTML = '';

      const attachments = pendingAttachments.map(a => ({
        id: a.id,
        name: a.name,
        mime_type: a.mime_type,
        size_bytes: a.size_bytes,
      }));
      const attachmentIds = attachments.map(a => a.id);

      renderMessage('user', text, history.length, null, attachments);
      history.push({ role: 'user', text, attachments });
      saveHistory(history);

      input.value = '';
      pendingAttachments = [];
      renderPendingAttachments();

      
      setSendingState(true);
      awaitingReply = true;
      const typingRow = panelOpen ? showTyping() : null;

      let queued;
      try {
        queued = await PTCAuth.askAssistant(text, currentCourseName(), getConvId(), attachmentIds);
      } catch (error) {
        typingRow?.remove();
        awaitingReply = false;
        setSendingState(false);
        renderMessage('assistant', error?.message || 'صار في خطأ، حاول مرة أخرى.');
        return;
      }

      await handleQueuedResponse(queued, history, text, undefined, typingRow);
    }

    
    async function summarizeFile(courseFileId, mode = 'summary') {
      
      if (sending) { openPanel(); return; }

      
      await new Promise(resolve => setTimeout(resolve, 0));
      if (sending) { openPanel(); return; }

      
      turnToken++;
      awaitingReply = false;
      setSendingState(false);

      openPanel();
      saveHistory([]);
      pendingAttachments = [];
      renderPendingAttachments();
      clearConvId();
      hideHistoryList();
      renderEmptyState();
      messagesBox.innerHTML = '';

      localStorage.setItem(USED_BEFORE_KEY, '1');
      button.classList.remove('ai-fab-invite');

      setSendingState(true);
      awaitingReply = true;

      const summarizeLabel = mode === 'flashcards' ? 'جارٍ إعداد بطاقات المراجعة...' : 'جارٍ تلخيص الملف...';
      
      const placeholderTypingRow = showTyping(summarizeLabel);

      let queued;
      try {
        queued = await PTCAuth.summarizeCourseFile(courseFileId, mode);
      } catch (error) {
        placeholderTypingRow.remove();
        awaitingReply = false;
        setSendingState(false);
        renderMessage('assistant', error?.message || 'تعذّر تلخيص الملف، حاول مرة أخرى.');
        return;
      }

      placeholderTypingRow.remove();

      const history = getHistory();
      renderMessage('user', queued.message_text, history.length);
      history.push({ role: 'user', text: queued.message_text, attachments: [] });
      saveHistory(history);

      const typingRow = showTyping(summarizeLabel);

      
      handleQueuedResponse(queued, history, queued.message_text, summarizeLabel, typingRow, true)
        .catch(() => {
          
        });
    }

    window.PTCAssistant = { summarizeFile };

    
    async function resumePendingIfAny() {
      const pending = getPending();
      if (!pending) return;

      const history = getHistory();
      await runTurn(pending.userText, history, pending.id, pending.typingLabel, undefined, pending.isFileQuestion);
    }

    
    async function regenerateAt(assistantIndex) {
      if (sending) return;

      const history = getHistory();
      const userIndex = assistantIndex - 1;

      if (userIndex < 0 || history[userIndex]?.role !== 'user') return;

      
      setSendingState(true);
      awaitingReply = true;

      const userText = history[userIndex].text;
      const userAttachments = history[userIndex].attachments || [];
      const userAttachmentIds = userAttachments.map(a => a.id).filter(Boolean);

      
      history.length = userIndex + 1;
      saveHistory(history);

      [...messagesBox.querySelectorAll('.ai-msg')]
        .slice(assistantIndex)
        .forEach(row => row.remove());

      let queued;
      try {
        queued = await PTCAuth.askAssistant(userText, currentCourseName(), getConvId(), userAttachmentIds);
      } catch (error) {
        awaitingReply = false;
        setSendingState(false);
        renderMessage('assistant', error?.message || 'صار في خطأ، حاول مرة أخرى.', assistantIndex);
        return;
      }

      await runTurn(userText, history, queued.question_id);
    }

    async function regenerateLast() {
      const history = getHistory();
      const lastUserIndex = [...history].reverse().findIndex(m => m.role === 'user');
      if (lastUserIndex === -1) return;

      const realIndex = history.length - 1 - lastUserIndex;
      await regenerateAt(realIndex + 1);
    }

    const isCoarsePointer = window.matchMedia && window.matchMedia('(pointer:coarse)').matches;

    
    
    
    
    function openPanel() {
      panelOpen = true;
      panel.classList.add('open');
      wrapper.classList.add('ai-panel-is-open');
      clearBadge();
      hideHistoryList();
      refreshCourseBadge();
      loadHistoryIntoPanel();
      refreshUsageCounter();
      if (awaitingReply) showTyping();
      if (!isCoarsePointer) setTimeout(() => input.focus(), 80);
      UIBackStack.push('aiPanel', () => {
        panelOpen = false;
        panel.classList.remove('open');
        wrapper.classList.remove('ai-panel-is-open');
      });
    }

    function closePanel() {
      panelOpen = false;
      panel.classList.remove('open');
      wrapper.classList.remove('ai-panel-is-open');
      UIBackStack.pop('aiPanel');
    }

    attachBtn.onclick = () => { clearAttachmentError(); attachMenu.hidden = !attachMenu.hidden; };
    panel.querySelector('#aiAttachDocBtn').onclick = () => { attachMenu.hidden = true; fileInputDocs.click(); };
    panel.querySelector('#aiAttachImgBtn').onclick = () => { attachMenu.hidden = true; fileInput.click(); };
    fileInput.addEventListener('change', () => handleFiles(fileInput.files, fileInput));
    fileInputDocs.addEventListener('change', () => handleFiles(fileInputDocs.files, fileInputDocs));
    document.addEventListener('click', e => {
      if (!attachMenu.hidden && !e.target.closest('.ai-attach-wrap')) attachMenu.hidden = true;
    });

    const toggleFab = () => (panelOpen ? closePanel() : openPanel());
    wrapper.addEventListener('click', toggleFab);
    wrapper.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleFab(); }
    });
    panel.querySelector('.ai-panel-close').onclick = closePanel;
    panel.querySelector('.ai-panel-minimize').onclick = closePanel;
    panel.querySelector('.ai-panel-new').onclick = () => {
      turnToken++;
      awaitingReply = false;
      setSendingState(false);
      saveHistory([]);
      pendingAttachments = [];
      renderPendingAttachments();
      clearConvId();
      hideHistoryList();
      renderEmptyState();
    };
    panel.querySelector('.ai-panel-reload').onclick = regenerateLast;
    panel.querySelector('.ai-panel-history').onclick = toggleHistoryList;
    historyBackBtn.onclick = hideHistoryList;
    panel.querySelector('.ai-panel-expand').onclick = () => {
      panel.classList.toggle('ai-panel-expanded');
      const isExpanded = panel.classList.contains('ai-panel-expanded');
      panel.querySelector('.ai-panel-expand').title = isExpanded ? 'إرجاع الحجم الطبيعي' : 'تكبير الشاشة';
    };

    sendBtn.onclick = send;
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') send();
    });

    panel.addEventListener('click', e => {
      if (!attachMenu.hidden && !e.target.closest('.ai-attach-wrap')) attachMenu.hidden = true;
      e.stopPropagation();
    });

    document.addEventListener('click', e => {
      if (panelOpen && !panel.contains(e.target) && !wrapper.contains(e.target)) {
        closePanel();
      }
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && panelOpen) closePanel();
    });

    resumePendingIfAny();
  }

  if (PTCAuth.user) mount();
  window.addEventListener('ptc-auth-change', mount);
})();








(function(){
  const mq = window.matchMedia('(max-width:520px)');
  function update(){
    if(!mq.matches){ document.body.classList.remove('fw-at-top'); return; }
    document.body.classList.toggle('fw-at-top', window.scrollY <= 8);
  }
  window.addEventListener('scroll', update, {passive:true});
  if (mq.addEventListener) mq.addEventListener('change', update);
  else if (mq.addListener) mq.addListener(update);
  window.addEventListener('DOMContentLoaded', update);
  update();
})();

/* ============================================================
   بطاقة تثبيت التطبيق (PWA Install Card) — خطوة ٨٠
   تظهر فقط لو المتصفح دعم فعليًا خاصية التثبيت (Chrome/Edge/Android
   بشكل أساسي — سفاري iOS ما بيدعم هذا الحدث إطلاقًا فما راح تظهر
   عنده، وهذا سلوك طبيعي متوقع لا يحتاج أي معالجة إضافية)، ولو
   التطبيق مو مثبّت أصلًا، ولو الطالب ما ضغط "لاحقًا" خلال آخر
   14 يوم.
   ============================================================ */
(function(){
  const DISMISS_KEY = 'ptc_pwa_install_dismissed_at';
  const DISMISS_DAYS = 14;

  function isStandalone(){
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator.standalone === true;
  }

  function recentlyDismissed(){
    let raw;
    try{ raw = localStorage.getItem(DISMISS_KEY); }catch(e){ return false; }
    if(!raw) return false;
    const days = (Date.now() - Number(raw)) / 86400000;
    return days < DISMISS_DAYS;
  }

  let deferredPrompt = null;
  let cardEl = null;
  let navBtnEl = null;

  /* أيقونة دائمة بشريط التنقّل (بجانب زر الوضع الليلي/النهاري) —
     تظهر فقط باللحظة اللي يصير فيها فعليًا حدث تثبيت جاهز من
     المتصفح (نفس لحظة ظهور أيقونة "تثبيت" الأصلية بشريط عنوان
     Chrome على سطح المكتب) — تثبيت مباشر بضغطة واحدة، بلا أي
     تخمين ولا داعي يدوّر الطالب بقوائم المتصفح بنفسه. */
  function buildNavButton(){
    if(navBtnEl) return navBtnEl;
    const host = document.querySelector('.nav-actions');
    if(!host) return null;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pwa-nav-btn';
    btn.setAttribute('aria-label', 'تثبيت التطبيق');
    btn.title = 'تثبيت التطبيق';
    btn.innerHTML = `${(typeof ic === 'function') ? ic('download', 18) : ''}<span class="pwa-nav-ping"></span><span class="pwa-nav-dot"></span>`;
    btn.addEventListener('click', doInstall);
    host.insertBefore(btn, host.firstChild);
    navBtnEl = btn;
    return btn;
  }

  function showNavButton(){
    if(isStandalone() || !deferredPrompt) return;
    const btn = buildNavButton();
    btn?.classList.add('show');
  }

  function hideNavButton(){
    navBtnEl?.classList.remove('show');
  }

  function buildCard(){
    if(cardEl) return cardEl;
    const el = document.createElement('div');
    el.className = 'pwa-install-card';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'تثبيت تطبيق زاد الطالب');
    const downloadIcon = (typeof ic === 'function') ? ic('download', 15) : '';
    el.innerHTML = `
      <div class="pwa-install-inner">
        <div class="pwa-install-glow-a"></div>
        <div class="pwa-install-glow-b"></div>
        <div class="pwa-install-row">
          <div class="pwa-install-icon-wrap">
            <div class="pwa-install-icon"><img src="icon-192.png" alt="أيقونة التطبيق" width="48" height="48"></div>
            <span class="pwa-install-badge"><i class="ping"></i><i></i></span>
          </div>
          <div class="pwa-install-body">
            <div class="pwa-install-title-row">
              <p class="pwa-install-title">تثبيت <b>التطبيق</b></p>
              <span class="pwa-install-tag">PWA</span>
            </div>
            <p class="pwa-install-desc">ثبّت المنصة على جهازك للوصول بضغطة واحدة، وتصفّح أسرع حتى على نت ضعيف.</p>
          </div>
        </div>
        <div class="pwa-install-actions">
          <button type="button" class="pwa-install-later">لاحقًا</button>
          <button type="button" class="pwa-install-go">${downloadIcon}<span>تثبيت الآن</span></button>
        </div>
      </div>`;
    document.body.appendChild(el);
    el.querySelector('.pwa-install-later').addEventListener('click', dismiss);
    el.querySelector('.pwa-install-go').addEventListener('click', doInstall);
    cardEl = el;
    return el;
  }

  function show(){
    if(isStandalone() || recentlyDismissed() || !deferredPrompt) return;
    const el = buildCard();
    requestAnimationFrame(()=> requestAnimationFrame(()=> el.classList.add('show')));
  }

  function hide(){
    if(cardEl) cardEl.classList.remove('show');
  }

  function dismiss(){
    try{ localStorage.setItem(DISMISS_KEY, String(Date.now())); }catch(e){}
    hide();
  }

  function doInstall(){
    if(!deferredPrompt){ hide(); return; }
    hide();
    deferredPrompt.prompt();
    deferredPrompt.userChoice.finally(()=>{ deferredPrompt = null; hideNavButton(); });
  }

  window.addEventListener('beforeinstallprompt', (e)=>{
    e.preventDefault();
    deferredPrompt = e;
    if(isStandalone()) return;
    showNavButton();
    if(recentlyDismissed()) return;
    setTimeout(show, 2500);
  });

  window.addEventListener('appinstalled', ()=>{
    hide();
    hideNavButton();
    deferredPrompt = null;
    try{ localStorage.removeItem(DISMISS_KEY); }catch(e){}
  });

  /* مدخل ثابت دائم (من قائمة الحساب) — يبقى شغّالًا حتى لو الطالب
     ضغط "لاحقًا" على البطاقة أو مرّت مدة الـ14 يوم لسه ما خلصت.
     لو المتصفح عنده فعليًا حدث التثبيت جاهز (deferredPrompt) بيفتح
     نافذة التثبيت الرسمية مباشرة؛ غير هيك بيعرض توجيه واضح حسب
     نوع الجهاز (خطوات يدوية بمتصفحات ما بترسل هذا الحدث إطلاقًا،
     زي سفاري آيفون). */
  function isIOS(){
    return /iphone|ipad|ipod/i.test(navigator.userAgent || '')
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function triggerFromMenu(){
    if(isStandalone()){
      if(typeof showToast === 'function') showToast('معلومة','التطبيق مثبّت أصلًا على هذا الجهاز','تقدر تفتحه من شاشتك الرئيسية مباشرة',30);
      return;
    }
    if(deferredPrompt){
      doInstall();
      return;
    }
    if(typeof showToast === 'function'){
      if(isIOS()){
        showToast('تثبيت التطبيق','بمتصفح سفاري: اضغط زر المشاركة (المربع والسهم) بالأسفل','ثم اختر «إضافة إلى الشاشة الرئيسية»',60);
      } else {
        showToast('تثبيت التطبيق','افتح قائمة المتصفح (⋮ أو ⋯) أعلى الشاشة','وابحث عن خيار «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية»',60);
      }
    }
  }

  window.PTCPWAInstall = { isStandalone, triggerFromMenu };
})();
