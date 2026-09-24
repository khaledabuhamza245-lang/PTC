/* بحث كتالوج المساقات: تطبيع عربي ومطابقة بالتوكِنز.

   ملفّ نقيّ بلا DOM ولا HTML — مدخله بيانات ومخرجه بيانات — فيُختبر
   بـ node مباشرة، على نفس نمط course-preview.js.

   يبقى في جذر ptc-hub-frontend لا في مجلد فرعي: فحص
   tests/frontend/xss-audit.js يقرأ الجذر بـ readdirSync غير تعاودي،
   فأي ملف داخل مجلد فرعي يخرج من التدقيق الأمني بصمت. */
(function () {

  /* نطاق التشكيل والتطويل بهروب Unicode لا بالمحارف الحرفية:
     هذه محارف عديمة العرض لا تظهر في المحرِّر، فتُفسد بصمت عند
     النسخ أو إعادة التنسيق. البقية حرفية لأنها مرئية وأوضح هكذا. */
  const DIACRITICS = /[\u064B-\u0652\u0640]/g;

  function normalize(text) {
    return String(text === null || text === undefined ? '' : text)
      .toLowerCase()
      .replace(DIACRITICS, '')
      .replace(/[أإآٱ]/g, 'ا')
      .replace(/ة/g, 'ه')
      .replace(/ى/g, 'ي')
      .replace(/ؤ/g, 'و')
      .replace(/ئ/g, 'ي');
  }

  /* نصّ المساق المجمَّع: العربي والإنجليزي والرمز والكلمات المفتاحية
     في سلسلة واحدة مطبَّعة. kw يُملأ من الـ API وكان مُهمَلًا في
     البحث القديم رغم وجوده لهذا الغرض بالضبط. */
  function haystack(course) {
    return normalize([course.ar, course.en, course.code, course.kw].join(' '));
  }

  /* كل كلمة في الاستعلام يجب أن تظهر في مكان ما — لا كسلسلة متّصلة.
     أسماء المساقات تحمل أداة تعريف بين كلماتها («اللغة الإنجليزية»)،
     فمطابقة السلسلة المتّصلة تفشل على أي استعلام من كلمتين. */
  function filter(catalog, query, excludeIds) {
    const skip = excludeIds || [];
    const tokens = normalize(query).split(/\s+/).filter(Boolean);

    return (catalog || []).filter(function (course) {
      if (skip.indexOf(course.id) !== -1) return false;
      if (!tokens.length) return true;

      const hay = haystack(course);
      return tokens.every(function (token) { return hay.indexOf(token) !== -1; });
    });
  }

  const api = { normalize: normalize, filter: filter };

  if (typeof window !== 'undefined') window.PTCCourseSearch = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
