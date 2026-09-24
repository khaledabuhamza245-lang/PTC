// سجل الأدوات الهندسية — خريطة ثابتة على نمط prep-guide.js.
//
// لماذا ملف ثابت لا جدول: الأدوات كود لا بيانات. لا تُضاف من لوحة
// تحكم ولا تتغير إلا بنشر جديد، فجدول لها يشتري مرونة لا تُستعمل
// بثمن هجرة و endpoints وواجهة إدارة.
//
// المفتاح رمز المساق كما في courses.code حرفيًا (بالمسافة)، ليطابق
// ما يصل من /structure بلا تطبيع.
const TOOLS_REGISTRY = {
  numbers: {
    id: 'numbers',
    name: 'محوّل أنظمة العد',
    icon: 'refresh',
    blurb: 'ثنائي وثماني وعشري وسداسي عشري، ومكمّل الاثنين بعرض ثابت.',
    courses: [
      'EEE1 3356',   // أساسيات المنطق الرقمي
      'EEE1 1356',   // مختبر أساسيات المنطق الرقمي
      'EEE4 3358',   // معمارية الحاسوب
      'EEE4 3465',   // تقنيات ربط الحاسوب
      'EEE6 3585',   // الأنظمة المدمجة
      'CMP0 3315'    // المهارات الرقمية
    ]
  },

  subnet: {
    id: 'subnet',
    name: 'حاسبة الشبكات الفرعية',
    icon: 'target',
    blurb: 'الشبكة والبث ومدى المضيفات والقناع، وتقسيم شبكة إلى فرعية.',
    courses: [
      'EEE4 3469',   // شبكات الحاسوب
      'EEE3 3498',   // أنظمة الاتصالات
      'EEE4 3576'    // أمن المعلومات والشبكات
    ]
  },

  truth: {
    id: 'truth',
    name: 'جدول الحقيقة',
    icon: 'layers',
    blurb: 'اكتب تعبيرًا بوليًا فيُبنى جدوله كاملًا ويُصنَّف منطقيًا.',
    courses: [
      'EEE1 3356',   // أساسيات المنطق الرقمي
      'EEE1 1356',   // مختبر أساسيات المنطق الرقمي
      'ACD0 3267'    // التحليل العددي
    ]
  },

  bigo: {
    id: 'bigo',
    name: 'مرجع Big-O',
    icon: 'chart',
    blurb: 'رتب النمو مقارنةً بالأرقام والرسم، لا بالترتيب وحده.',
    courses: [
      'EEE4 3254',   // الخوارزميات وتركيب البيانات
      'EEE4 4150',   // برمجة الحاسوب
      'EEE4 3354',   // البرمجة الكائنية الموجهة
      'EEE4 3253'    // لغات برمجة حديثة
    ]
  },

  kmap: {
    id: 'kmap',
    name: 'تبسيط بولي (K-Map)',
    icon: 'target',
    blurb: 'Quine–McCluskey: نفس جواب خريطة كارنو، ولعدد متغيّرات أكبر.',
    courses: [
      'EEE1 3356',   // أساسيات المنطق الرقمي
      'EEE1 1356',   // مختبر أساسيات المنطق الرقمي
      'EEE4 3358'    // معمارية الحاسوب
    ]
  },

  glossary: {
    id: 'glossary',
    name: 'قاموس المصطلحات',
    icon: 'bookOpen',
    blurb: 'مصطلح إنجليزي، ترجمته، وشرح بسطر واحد.',
    /* عابر للمساقات: يُوصَل من صفحة الأدوات لا من بطاقة مساق بعينه،
       والربط بالمساق داخل القاموس نفسه عبر PTCGlossary.forCourse. */
    courses: []
  }
};

/* أدوات مرتبطة بمساق بعينه. يُستدعى من صفحة المساق. */
function toolsForCourse(code) {
  const key = String(code || '').trim();

  if (!key) return [];

  return Object.values(TOOLS_REGISTRY)
    .filter(tool => tool.courses.indexOf(key) !== -1);
}

if (typeof window !== 'undefined') {
  window.PTCTools = {
    registry: TOOLS_REGISTRY,
    forCourse: toolsForCourse
  };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    registry: TOOLS_REGISTRY,
    forCourse: toolsForCourse
  };
}
