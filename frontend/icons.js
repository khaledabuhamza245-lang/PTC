// ============================================================
//  نظام الأيقونات — SVG احترافي بدل الإيموجي
//  الاستخدام:  <i data-icon="home"></i>   أو   ic('home')
//  الأيقونات ترث لون النص تلقائياً (currentColor)
// ============================================================
const ICONS = {
  // ---------- تنقّل ----------
  home:'<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
  layers:'<path d="M12 3 3 8l9 5 9-5z"/><path d="M3 13l9 5 9-5"/>',
  book:'<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19.5V5"/>',
  target:'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
  mosque:'<path d="M12 2c2 2 3.5 3.2 3.5 5 0 1.4-1.4 2.3-3.5 2.3S8.5 8.4 8.5 7c0-1.8 1.5-3 3.5-5z"/><path d="M4 21v-6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v6"/><path d="M4 21h16"/><path d="M9 21v-3a3 3 0 0 1 6 0v3"/>',
  menu:'<path d="M4 7h16M4 12h16M4 17h16"/>',
  back:'<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
  arrowLeft:'<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
  // سهم العودة في واجهة عربية يشير يمينًا — مرآة arrowLeft لا نسخته.
  arrowRight:'<path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>',
  chevronDown:'<path d="M6 9l6 6 6-6"/>',
  chevronUp:'<path d="M6 15l6-6 6 6"/>',
  /*
   * كانت مستخدَمة (course-page.js، زرّ "الإبلاغ عن مشكلة" بكل عنصر
   * محتوى) بدون ما تكون معرَّفة هنا أصلًا — فيَعُد renderIcons() لا
   * يجد لها مطابقة بـICONS ويترك <i data-icon="flag"></i> كما هي بلا
   * أي رسمة، فيظهر الزرّ فارغًا تمامًا رغم تنسيقه الصحيح (لون كهرماني،
   * حدود واضحة) — هذه هي الإضافة الناقصة لا مجرّد مشكلة تنسيق.
   */
  flag:'<path d="M5 21V4"/><path d="M5 4h13l-3 4 3 4H5"/>',
  external:'<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',

  // ---------- دراسة ----------
  graduation:'<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5"/>',
  mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  clipboard:'<rect x="8" y="3" width="8" height="4" rx="1"/><path d="M16 5h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
  notes:'<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M19 8v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z"/><path d="M9 13h6M9 17h4"/>',
  file:'<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M19 8v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z"/>',
  folder:'<path d="M3 7.5A2 2 0 0 1 5 5.5h3.6a1 1 0 0 1 .8.4L11 8h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  image:'<rect x="3" y="4.5" width="18" height="15" rx="2"/><circle cx="8.5" cy="9.8" r="1.4"/><path d="M21 15.5 16 10.5l-8 8"/>',
  code:'<path d="M15.5 7.5 20 12l-4.5 4.5"/><path d="M8.5 7.5 4 12l4.5 4.5"/><path d="M13.5 4.5 10.5 19.5"/>',
  paperclip:'<path d="M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 1 1 5 5l-8 8a2 2 0 1 1-3-3l7-7"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.4-4.4"/>',
  link:'<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
  chart:'<path d="M3 3v18h18"/><path d="M7 15l3-4 3 3 5-7"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  timer:'<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 1.5"/><path d="M9 2h6"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  checkCircle:'<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
  circleDot:'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>',
  bookOpen:'<path d="M12 6.5C10.5 5 8.5 4.5 5 4.5v13c3.5 0 5.5.5 7 2 1.5-1.5 3.5-2 7-2v-13c-3.5 0-5.5.5-7 2z"/><path d="M12 6.5V21"/>',
  trophy:'<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a2 2 0 0 0 2 3"/><path d="M16 6h3a2 2 0 0 1-2 3"/><path d="M10 13h4v3h-4z"/><path d="M8 20h8"/><path d="M12 16v4"/>',
  // وسام لا كأس: الامتحان تقدير على ورقة، والكأس يقرأ احتفالًا بالفوز.
  award:'<circle cx="12" cy="9" r="5.5"/><path d="M8.6 13.6 7 22l5-2.6L17 22l-1.6-8.4"/>',
  flame:'<path d="M12 22c4 0 6-2.7 6-6 0-4-3-5-3-9 0 0-2 1.5-2 4 0-2-1-3.5-2-4.5C10 9 6 11 6 16c0 3.3 2 6 6 6z"/>',

  // ---------- روحاني ----------
  leaf:'<path d="M4 20c8 2 16-3 16-13 0-1.5-.2-2.5-.5-3.5C11 2 4 8 4 16z"/><path d="M9 15c2-2 5-4 8-5"/>',
  sunrise:'<path d="M12 3v4"/><path d="M5.6 9.6 8 12"/><path d="M2 17h20"/><path d="M18.4 9.6 16 12"/><path d="M8 17a4 4 0 0 1 8 0"/><path d="M3 21h18"/>',
  moon:'<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>',
  stars:'<path d="M12 3l1.6 4.2L18 8.8l-3.4 2.8L15.4 16 12 13.7 8.6 16l.8-4.4L6 8.8l4.4-1.6z"/>',
  quran:'<path d="M4 5.5C4 4.7 4.7 4 5.5 4H18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5.5A1.5 1.5 0 0 1 4 18.5z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  quote:'<path d="M8 11H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v6c0 2-1 3.5-3 4"/><path d="M19 11h-3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v6c0 2-1 3.5-3 4"/>',
  hands:'<path d="M7 11V6.5a1.5 1.5 0 0 1 3 0V11"/><path d="M10 11V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M13 11V6.5a1.5 1.5 0 0 1 3 0V12"/><path d="M16 12v-1.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1a7 7 0 0 1-7-7v-3a1.5 1.5 0 0 1 3 0"/>',
  beads:'<circle cx="12" cy="4" r="1.6"/><circle cx="17.6" cy="6.4" r="1.6"/><circle cx="20" cy="12" r="1.6"/><circle cx="17.6" cy="17.6" r="1.6"/><circle cx="12" cy="20" r="1.6"/><circle cx="6.4" cy="17.6" r="1.6"/><circle cx="4" cy="12" r="1.6"/><circle cx="6.4" cy="6.4" r="1.6"/>',
  bookmark:'<path d="M6 4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17l-6-4-6 4z"/>',
  volume:'<path d="M11 5 6 9H3v6h3l5 4z"/><path d="M16 9a4 4 0 0 1 0 6"/><path d="M19 6.5a8 8 0 0 1 0 11"/>',
  pause:'<rect x="7" y="5" width="4" height="14" rx="1"/><rect x="13" y="5" width="4" height="14" rx="1"/>',
  play:'<path d="M7 4.5v15l13-7.5z"/>',

  // ---------- حساب وإدارة ----------
  user:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/>',
  // نفس شكل user، باسم صريح لاستخدامه في قائمة فوتر المساهمين للمهندسين.
  userMale:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/>',
  // دائرة الرأس + جسم بشكل مثلث (فستان) بدل الشبه المنحرف، تمييزًا للمهندسات
  // بقائمة فوتر المساهمين — اصطلاح شائع ومحايد، لا علاقة له بالكفاءة أو الدور.
  userFemale:'<circle cx="12" cy="7.2" r="3.4"/><path d="M8 21l4-8.4 4 8.4"/><path d="M9.3 16.4h5.4"/>',
  // شخصان متجاوران متطابقان، لا واحد خلف الآخر: لا أنصاف دوائر ولا أقواس
  // مبتورة تسبح في الفراغ.
  //
  // التجاور يفرض مقايضة: كل توسيع للجسمين يقضم الفراغ بينهما. الجسم هنا
  // نصف قطره ٤٫٩ فيبقى الفراغ ١٫٨ وحدة = ١٫٢٧px عند مقاس ١٧px، أي بقدر
  // سُمك الخط تمامًا — وهذا هو الحدّ الأدنى قبل أن يلتحم الجسمان ويُقرآ
  // شكلًا واحدًا. لا تُوسَّع أكثر من ذلك دون تقليص الرؤوس أو الارتفاع.
  //
  // الامتداد ١٫٣–٢٢٫٧ أفقيًّا و٤٫٤–٢٠ رأسيًّا يوازن وزن graduation المجاورة.
  users:'<circle cx="6.2" cy="7.9" r="3.5"/><path d="M1.3 20v-2.9a4.9 4.9 0 0 1 9.8 0v2.9"/><circle cx="17.8" cy="7.9" r="3.5"/><path d="M12.9 20v-2.9a4.9 4.9 0 0 1 9.8 0v2.9"/>',
  lock:'<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  key:'<circle cx="8" cy="15" r="4"/><path d="M11 12 21 2"/><path d="M17 6l2.5 2.5"/><path d="M14.5 8.5 17 11"/>',
  settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
  megaphone:'<path d="M4 10v4a1 1 0 0 0 1 1h2l7 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
  refresh:'<path d="M20 12a8 8 0 0 1-13.7 5.7L4 15.4"/><path d="M4 12a8 8 0 0 1 13.7-5.7L20 8.6"/><path d="M4 20v-4.6h4.6"/><path d="M20 4v4.6h-4.6"/>',
  upload:'<path d="M12 15V4"/><path d="M8 8l4-4 4 4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
  download:'<path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
  logout:'<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h9"/>',
  trash:'<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  close:'<path d="M6 6l12 12M18 6 6 18"/>',
  edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  pin:'<path d="M12 17v5"/><path d="M9 3h6l-1 6 3 3v2H7v-2l3-3z"/>',
  star:'<path d="M12 3.5l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L3.5 9.7l5.9-.9z"/>',
  bell:'<path d="M18 8a6 6 0 0 0-12 0c0 6-2 7-2 7h16s-2-1-2-7"/><path d="M10.3 20a2 2 0 0 0 3.4 0"/>',
  sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4M16 3v4"/>',
  info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="7.8" r=".9" fill="currentColor" stroke="none"/>',
  warning:'<path d="M12 4 2.5 20h19z"/><path d="M12 10v4"/><circle cx="12" cy="17" r=".9" fill="currentColor" stroke="none"/>',

  // طائرة الإرسال — تُقرأ «تيليجرام» فورًا وتبقى بلغة الطقم الخطّية،
  // بعكس شعار تيليجرام المصمت الذي يفرض fill ويصير جسمًا غريبًا هنا.
  send:'<path d="M21.5 2.5 10.8 13.2"/><path d="M21.5 2.5 14.7 21.5l-3.9-8.3-8.3-3.9z"/>',

  // ---------- إضافات: بديل الإيموجي بباقي الموقع ----------
  eye:'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
  eyeOff:'<path d="M3 3l18 18"/><path d="M10.6 5.2A10 10 0 0 1 22 12s-1 2.3-3.2 4.2M6.5 6.6C4 8.3 2 12 2 12s3.5 7 10 7c1.6 0 3.1-.4 4.3-1"/><path d="M9.5 9.8a3 3 0 0 0 4.3 4.3"/>',
  globe:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c2.5 2.5 4 5.8 4 9s-1.5 6.5-4 9c-2.5-2.5-4-5.8-4-9s1.5-6.5 4-9z"/>',
  building:'<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1"/><path d="M9 21v-3h6v3"/>',
  flask:'<path d="M9 3h6"/><path d="M10 3v6.3L4.6 18a2 2 0 0 0 1.7 3h11.4a2 2 0 0 0 1.7-3L14 9.3V3"/><path d="M7.5 15h9"/>',
  bulb:'<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.9V17h5v-1.2c0-.8.4-1.5 1-1.9A6 6 0 0 0 12 3z"/>',
  compass:'<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>',
  smartphone:'<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
  wrench:'<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.8-.8-.8-2.8z"/>',
  camera:'<path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>',
};

// يبني وسم SVG جاهز
function ic(name, size){
  const p = ICONS[name];
  if(!p) return '';
  const s = Number(size) || 18;
  return `<svg class="ic" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
    aria-hidden="true" focusable="false">${p}</svg>`;
}

// يستبدل كل <i data-icon="x"></i> بالأيقونة المناسبة
function renderIcons(root){
  (root||document).querySelectorAll('i[data-icon]').forEach(el=>{
    const name=el.getAttribute('data-icon');
    const size=el.getAttribute('data-size');
    if(ICONS[name]){ el.outerHTML=ic(name,size?+size:undefined); }
  });
}
function initIconRenderer(){
  renderIcons();
  if(!document.body) return;
  const observer=new MutationObserver(records=>{
    records.forEach(record=>record.addedNodes.forEach(node=>{
      if(node.nodeType!==1) return;
      if(node.matches?.('i[data-icon]')) renderIcons(node.parentElement||document);
      else if(node.querySelector?.('i[data-icon]')) renderIcons(node);
    }));
  });
  observer.observe(document.body,{childList:true,subtree:true});
}
if(document.readyState==='loading') window.addEventListener('DOMContentLoaded',initIconRenderer,{once:true});
else initIconRenderer();
