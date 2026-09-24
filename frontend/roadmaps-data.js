/*
 * بيانات خرائط الطريق — بُنية بسيطة عمدًا: مصفوفة مجالات، كل مجال
 * مصفوفة مراحل، كل مرحلة مصفوفة مصادر. إضافة مجال جديد لاحقًا يعني
 * إضافة كائن هنا فقط، بلا أي لمسة على منطق العرض بـroadmaps-page.js.
 *
 * كل مصدر هنا حقيقي ومُتحقَّق منه وقت الكتابة (بحث ويب مباشر لا حفظًا
 * من الذاكرة) — أسماء أدوات ولغات برمجة تتغيّر بسرعة، فالثقة بمصدر
 * قديم أخطر من عدم وجوده أصلًا.
 */
window.PTC_ROADMAPS = [
  {
    id: 'webdev',
    icon: '<i data-icon="globe"></i>',
    title: 'تطوير الويب',
    subtitle: 'Web Development',
    description: 'من أول صفحة HTML لحالها، لصنع تطبيقات ويب كاملة بواجهة وخادم وقاعدة بيانات.',
    fullDescription: 'تطوير الويب من أكثر التخصصات وضوحًا بمسارها وسرعة بظهور نتائجها — خلال أسابيع بتقدر تبني صفحتك الأولى، وخلال أشهر بتقدر تبني تطبيقًا كاملًا. هاي الخريطة تاخدك خطوة خطوة من الصفر، بمصادر مجانية وممتازة فقط — عربية وإنجليزية معًا.',
    stages: [
      {
        title: 'HTML — هيكل الصفحة',
        subtitle: 'اللبنة الأولى لأي شيء على الويب',
        resources: [
          {
            title: 'كورس HTML كامل — أكاديمية الزيرو',
            url: 'https://elzero.org/category/courses/html-course/',
            lang: 'ar',
            type: 'كورس فيديو',
            duration: '~4 ساعات',
            learn: [
              'كل عناصر HTML الأساسية (عناوين، فقرات، روابط، صور)',
              'الجداول والنماذج (Forms) وكيف تُبنى بشكل صحيح',
              'الدلالات الصحيحة (Semantic HTML) وأهميتها لمحركات البحث',
              'مشروع عملي كامل من الصفر'
            ],
            note: 'المرجع العربي الأشهر بلا منازع لهذا الموضوع — يبدأ من الصفر المطلق.'
          },
          {
            title: 'MDN Web Docs — دليل HTML',
            url: 'https://developer.mozilla.org/en-US/docs/Web/HTML',
            lang: 'en',
            type: 'مرجع مكتوب',
            duration: 'مرجع دائم',
            learn: [
              'توثيق دقيق لكل وسم (Tag) وكل خاصية',
              'أمثلة تفاعلية قابلة للتجربة مباشرة',
              'يُحدَّث باستمرار مع كل تغيير بمعايير الويب'
            ],
            note: 'ارجع له كمرجع دائم أثناء العمل، لا لدراسته من أوله لآخره دفعة واحدة.'
          }
        ]
      },
      {
        title: 'CSS — التصميم والتنسيق',
        subtitle: 'كيف تجعل الصفحة جميلة ومرتّبة',
        resources: [
          {
            title: 'كورس CSS كامل — أكاديمية الزيرو',
            url: 'https://elzero.org/category/courses/css-course/',
            lang: 'ar',
            type: 'كورس فيديو',
            duration: '~10 ساعات',
            learn: [
              'الألوان والخطوط والمسافات (Box Model)',
              'Flexbox وCSS Grid لترتيب العناصر باحتراف',
              'التأثيرات والانتقالات (Transitions & Animations)',
              'بناء قالب كامل من الصفر خطوة بخطوة'
            ],
            note: 'أطول وأشمل كورس CSS عربي موجود — يستاهل الوقت المستثمر فيه.'
          },
          {
            title: 'HTML & CSS Practice — تطبيق عملي',
            url: 'https://elzero.org/category/courses/html-and-css-practice/',
            lang: 'ar',
            type: 'مشروع تطبيقي',
            duration: '~6.5 ساعات',
            learn: [
              'تطبيق كل ما تعلمته على قالب حقيقي كامل',
              'حل مشاكل تصميم واقعية لا نظرية فقط'
            ],
            note: 'لا تنتقل للمرحلة التالية قبل ما تجرّب هذا — الفرق بين الفهم والتطبيق كبير.'
          }
        ]
      },
      {
        title: 'التصميم المتجاوب',
        subtitle: 'Responsive Design — نفس الموقع، كل الشاشات',
        resources: [
          {
            title: 'freeCodeCamp — Responsive Web Design',
            url: 'https://www.freecodecamp.org/learn/2022/responsive-web-design/',
            lang: 'en',
            type: 'كورس تفاعلي مجاني',
            duration: '~15 ساعة',
            learn: [
              'Media Queries وكيف يتغيّر التصميم حسب حجم الشاشة',
              'مبدأ Mobile First بالتصميم',
              'شهادة مجانية معتمدة بنهاية الكورس'
            ],
            note: 'تطبيق عملي مباشر بالمتصفح — تكتب كود وتشوف نتيجته فورًا.'
          }
        ]
      },
      {
        title: 'JavaScript — البرمجة والتفاعل',
        subtitle: 'تحويل الصفحة الساكنة لتطبيق حي',
        resources: [
          {
            title: 'JavaScript Bootcamp — أكاديمية الزيرو',
            url: 'https://elzero.org/category/courses/javascript-bootcamp/',
            lang: 'ar',
            type: 'كورس فيديو',
            duration: '~19.5 ساعة (188 درسًا)',
            learn: [
              'المتغيرات، الدوال، والمصفوفات من الصفر',
              'التعامل مع الصفحة (DOM) والأحداث (Events)',
              'البرمجة غير المتزامنة (Async) وطلبات الشبكة',
              'أهم كورس JavaScript عربي من حيث الشمولية والحداثة'
            ],
            note: 'الأحدث والأشمل من كورسات الزيرو بالجافاسكريبت — يستحق أن يكون مرجعك الأساسي.'
          },
          {
            title: 'JavaScript.info',
            url: 'https://javascript.info',
            lang: 'en',
            type: 'مرجع شامل',
            duration: 'مرجع دائم',
            learn: [
              'شرح دقيق لكل تفصيلة بلغة JavaScript',
              'تمارين تفاعلية بنهاية كل درس'
            ],
            note: 'يجمع خبراء المجال على أنه الأفضل عالميًا لهذا الموضوع.'
          },
          {
            title: 'JavaScript OOP — أكاديمية الزيرو',
            url: 'https://elzero.org/category/courses/javascript-oop/',
            lang: 'ar',
            type: 'كورس فيديو',
            duration: '~5 ساعات',
            learn: [
              'البرمجة الكائنية (OOP) بلغة JavaScript تحديدًا',
              'Prototype وClasses وطرق تعريف الكائنات المختلفة'
            ],
            note: 'مرحلة متوسطة — لا تبدأ فيها قبل إتقان أساسيات JavaScript أولًا.'
          }
        ]
      },
      {
        title: 'Git وGitHub',
        subtitle: 'مهارة غير اختيارية لأي مبرمج',
        resources: [
          {
            title: 'Git & GitHub — دليل رسمي',
            url: 'https://docs.github.com/en/get-started',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~3 ساعات للأساسيات',
            learn: [
              'حفظ نسخ من مشروعك والتراجع عند الحاجة',
              'العمل الجماعي على نفس المشروع دون تعارض',
              'رفع مشاريعك على GitHub كمعرض أعمال حقيقي'
            ],
            note: 'أي فريق عمل بيتوقعها منك من أول يوم — لا تؤجّلها لآخر الطريق.'
          }
        ]
      },
      {
        title: 'الأطر الحديثة',
        subtitle: 'React — بناء واجهات ديناميكية كبيرة',
        resources: [
          {
            title: 'React — الوثائق الرسمية',
            url: 'https://react.dev',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~10 ساعات للأساسيات',
            learn: [
              'المكوّنات (Components) وكيف تُبنى الواجهة منها',
              'الحالة (State) وكيف تتفاعل الواجهة مع تغيّر البيانات',
              'Hooks — الطريقة الحديثة لكتابة React'
            ],
            note: 'أعيدت كتابتها بالكامل بأسلوب تعليمي ممتاز، تبدأ من الصفر فعليًا.'
          },
          {
            title: 'Next.js — الوثائق الرسمية',
            url: 'https://nextjs.org/docs',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~8 ساعات',
            learn: [
              'بناء مواقع React جاهزة للنشر مباشرة',
              'التوجيه (Routing) وتحسين الأداء تلقائيًا'
            ],
            note: 'الخطوة الطبيعية بعد إتقان React لبناء مواقع حقيقية.'
          }
        ]
      },
      {
        title: 'الخادم وقواعد البيانات',
        subtitle: 'Backend — المنطق المختفي خلف كل تطبيق',
        resources: [
          {
            title: 'Node.js — الوثائق الرسمية',
            url: 'https://nodejs.org/en/docs',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~6 ساعات للأساسيات',
            learn: [
              'تشغيل JavaScript خارج المتصفح',
              'بناء خادم بسيط من الصفر'
            ],
            note: 'نفس اللغة اللي تعلمتها بالواجهة، بسياق جديد كليًا.'
          },
          {
            title: 'freeCodeCamp — Back End Development and APIs',
            url: 'https://www.freecodecamp.org/learn/back-end-development-and-apis/',
            lang: 'en',
            type: 'كورس تفاعلي مجاني',
            duration: '~20 ساعة',
            learn: [
              'بناء REST APIs باستخدام Express',
              'الاتصال بقاعدة بيانات MongoDB',
              'مشاريع خلفية كاملة بشهادة مجانية'
            ]
          }
        ]
      },
      {
        title: 'الاحتراف والانطلاق',
        subtitle: 'مشاريع حقيقية، نشر، ومعرض أعمال',
        resources: [
          {
            title: 'roadmap.sh — Frontend / Backend',
            url: 'https://roadmap.sh',
            lang: 'en',
            type: 'خريطة مرجعية',
            duration: 'مرجع دائم',
            learn: [
              'خريطة مجتمعية تراجع بها ما تبقّى ينقصك بدقة',
              'مقارنة تفصيلية بين الأدوات والأطر المختلفة'
            ],
            note: 'محدَّثة باستمرار من مجتمع ضخم من المطورين حول العالم.'
          },
          {
            title: 'بناء ونشر 2-3 مشاريع حقيقية على GitHub',
            url: 'https://github.com',
            lang: 'ar',
            type: 'ممارسة عملية',
            duration: 'حسب المشروع',
            learn: [
              'معرض أعمال حقيقي يشوفه أي جهة توظيف',
              'ثقة عملية أكبر من أي شهادة كورس'
            ],
            note: 'ابدأ بمشروع بسيط وانشره فعليًا — الكمال يأتي بالتكرار لا بالانتظار.'
          }
        ]
      }
    ]
  },
  {
    id: 'mobile',
    icon: '<i data-icon="smartphone"></i>',
    title: 'تطوير تطبيقات الموبايل',
    subtitle: 'Mobile Development',
    description: 'من اختيار المسار الصح (أصلي أو متعدد المنصات) لنشر أول تطبيق لك على المتجر.',
    fullDescription: 'أكتر من 7 مليار مستخدم هاتف حول العالم، وسوق التطبيقات بيكبر باستمرار. هاي الخريطة بتساعدك تختار المسار الصح من البداية (مش تتوه بين عشرات الأطر)، وتوصل لتطبيق منشور فعليًا على المتجر.',
    stages: [
      {
        title: 'اختيار المسار',
        subtitle: 'أصلي أم متعدد المنصات؟ القرار الأهم بالبداية',
        resources: [
          {
            title: 'Flutter — الوثائق الرسمية',
            url: 'https://docs.flutter.dev',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~5 ساعات للأساسيات',
            learn: [
              'لغة Dart من الصفر',
              'لماذا يُعتبر الخيار الأشهر عالميًا لمتعدد المنصات',
              'أداء قريب جدًا من التطبيقات الأصلية'
            ]
          },
          {
            title: 'Kotlin — الوثائق الرسمية',
            url: 'https://kotlinlang.org/docs/home.html',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~4 ساعات للأساسيات',
            learn: [
              'أساسيات لغة Kotlin',
              'الخيار الأفضل لو قرّرت التخصص بأندرويد الأصلي حصرًا'
            ]
          }
        ]
      },
      {
        title: 'أساسيات المنصة',
        subtitle: 'بناء أول واجهة وفهم دورة حياة التطبيق',
        resources: [
          {
            title: 'Android Developers — التدريب الرسمي',
            url: 'https://developer.android.com/courses',
            lang: 'en',
            type: 'كورس رسمي مجاني',
            duration: '~20 ساعة',
            learn: [
              'دورة حياة التطبيق (Activity Lifecycle)',
              'بناء واجهات باستخدام Jetpack Compose',
              'محدَّث باستمرار مباشرة من جوجل نفسها'
            ]
          },
          {
            title: 'Flutter — Cookbook',
            url: 'https://docs.flutter.dev/cookbook',
            lang: 'en',
            type: 'أمثلة عملية',
            duration: 'مرجع دائم',
            learn: [
              'حلول جاهزة لمشاكل شائعة (تنقّل، نماذج، شبكة)',
              'أمثلة كاملة قابلة للنسخ والتجربة مباشرة'
            ]
          }
        ]
      },
      {
        title: 'بناء تطبيق حقيقي',
        subtitle: 'إدارة الحالة، التنقّل بين الشاشات، والاتصال بالإنترنت',
        resources: [
          {
            title: 'React Native — الوثائق الرسمية',
            url: 'https://reactnative.dev/docs/getting-started',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~8 ساعات',
            learn: [
              'بناء تطبيقات موبايل بلغة JavaScript',
              'إعادة استخدام معرفتك بـReact لو جاي من مسار الويب'
            ],
            note: 'خيار قوي جدًا إذا عندك خلفية سابقة بـJavaScript/React.'
          },
          {
            title: 'Firebase — الوثائق الرسمية',
            url: 'https://firebase.google.com/docs',
            lang: 'en',
            type: 'مرجع رسمي',
            duration: '~4 ساعات للأساسيات',
            learn: [
              'قاعدة بيانات جاهزة بأقل إعداد ممكن',
              'تسجيل دخول وإدارة مستخدمين بدون خادم خاص'
            ],
            note: 'مثالي لمشروعك الأول — يوفّر عليك بناء خادم من الصفر.'
          }
        ]
      },
      {
        title: 'الاحتراف والنشر',
        subtitle: 'اختبار التطبيق ونشره فعليًا على المتجر',
        resources: [
          {
            title: 'Google Play Console — دليل النشر',
            url: 'https://support.google.com/googleplay/android-developer',
            lang: 'en',
            type: 'دليل رسمي',
            duration: '~2-3 ساعات',
            learn: [
              'كل خطوات نشر تطبيقك الأول على المتجر',
              'متطلبات جوجل الجديدة للتحقق من هوية المطوّر (2026)'
            ]
          },
          {
            title: 'roadmap.sh — Android / Flutter / React Native',
            url: 'https://roadmap.sh',
            lang: 'en',
            type: 'خريطة مرجعية',
            duration: 'مرجع دائم',
            learn: [
              'مراجعة شاملة لما تبقّى حسب المسار اللي اخترته تحديدًا'
            ]
          }
        ]
      }
    ]
  }
];

/*
 * ملاحظة صراحة: محتوى الموبايل العربي المرئي (خصوصًا Flutter) أضعف
 * بكثير من محتوى الويب — لا يوجد إجماع واضح على قناة واحدة بنفس قوة
 * "الزيرو" بالويب. فُضِّل عدم إقحام قنوات لم يتأكَّد من جودتها بدل
 * تعويض النقص بمصدر ضعيف.
 */
