(function(){
  let rendered=false;
  const esc=v=>(typeof PTCUtils!=='undefined'&&PTCUtils.escapeHTML)?PTCUtils.escapeHTML(String(v??'')):String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const page=()=> (location.pathname.split('/').pop()||'index.html').toLowerCase();
  function normalCard(c){return `<div class="course course-clickable" id="${esc(c.key)}" data-dynamic-course="1" data-course-key="${esc(c.key)}" role="link" tabindex="0"><button class="course-head" aria-expanded="false" type="button"><span class="left"><span class="badge">${esc(c.code||'—')}</span><span><h3>${esc(c.name_en||c.name_ar||'Course')}</h3><span class="sub">${esc(c.name_ar||c.name_en||'مادة')}</span></span></span><span style="display:flex;align-items:center;gap:12px"><span class="ch-tag">مادة مضافة</span><span class="chev">↗</span></span></button></div>`;}
  function electiveCard(c){return `<div class="elec course-clickable" data-dynamic-course="1" data-course-key="${esc(c.key)}" role="link" tabindex="0"><div class="top"><span class="code2">${esc(c.code||'—')}</span><span class="ch2">فتح ↗</span></div><h4>${esc(c.name_en||c.name_ar||'Course')}</h4><div class="ar">${esc(c.name_ar||c.name_en||'مادة')}</div></div>`;}
  function updateCounts(){
    document.querySelectorAll('.sem-panel').forEach((panel,index)=>{const count=panel.querySelectorAll('.course').length;const badge=document.querySelectorAll('.sem-tab')[index]?.querySelector('.ch');if(badge)badge.textContent=`${count} مواد`;});
    const grid=document.querySelector('.elec-grid');if(grid){const first=document.querySelector('.sem-summary span');if(first)first.innerHTML=`<b>عدد المساقات المتاحة:</b> ${grid.querySelectorAll('.elec').length}`;}
  }
  async function render(){
    if(rendered||typeof PTCAuth==='undefined')return;const current=page();if(!/^year[1-4]\.html$/.test(current)&&current!=='electives.html')return;
    try{
      const courses=await PTCAuth.getCourses();const existing=new Set([...document.querySelectorAll('[data-course-key],.course[id]')].map(e=>e.dataset.courseKey||e.id));
      for(const c of courses.filter(c=>(c.page||'').toLowerCase()===current)){
        if(!c.key||existing.has(c.key))continue;
        if(current==='electives.html')document.querySelector('.elec-grid')?.insertAdjacentHTML('beforeend',electiveCard(c));
        else{const semester=Number(c.semester);const local=semester>2?((semester-1)%2)+1:semester;document.querySelector(`.sem-panel[data-sem="${local-1}"]`)?.insertAdjacentHTML('beforeend',normalCard(c));}
        existing.add(c.key);
      }
      rendered=true;updateCounts();window.PTCIcons?.refresh?.();
    }catch(error){console.error('تعذّر تحميل المواد المضافة:',error);}
  }
  window.addEventListener('ptc-auth-change',render);window.addEventListener('DOMContentLoaded',()=>setTimeout(render,0));
})();
