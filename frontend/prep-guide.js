// ماذا أراجع قبل المادة؟ — مواضيع يُنصح بمراجعتها قبل كل مساق.
// كل عنصر: {topic: "الموضوع", link: "" } — الرابط اختياري ويُضاف مستقبلاً.
const PREP_GUIDE = {
  "EEE4 4150": { name:"Computer Programming", ar:"برمجة الحاسوب", topics:[
    "Variables & Data Types","Operators & Expressions","Conditionals (if/switch)","Loops (for/while)","Basic I/O","Problem-Solving & Flowcharts"
  ]},
  "EEE1 3259": { name:"Electronics", ar:"إلكترونيات", topics:[
    "Ohm's Law","Basic Circuit Analysis","Semiconductors Basics","Diodes","Voltage & Current fundamentals"
  ]},
  "EEE1 3258": { name:"Electrical Circuits", ar:"دوائر كهربائية", topics:[
    "Ohm's Law","Kirchhoff's Laws (KVL/KCL)","Series & Parallel Circuits","Basic Algebra","Complex Numbers basics"
  ]},
  "EEE4 3354": { name:"Object Oriented Programming", ar:"البرمجة الكائنية", topics:[
    "C++/Java Syntax","Functions & Scope","Arrays & Strings","Classes & Objects intro","Basic memory model"
  ]},
  "EEE1 3356": { name:"Digital Logic Fundamentals", ar:"أساسيات المنطق الرقمي", topics:[
    "Number Systems (Binary/Hex/Octal)","Boolean Algebra","Logic Gates (AND/OR/NOT)","Truth Tables","Binary Arithmetic"
  ]},
  "EEE3 3350": { name:"Signals and Systems", ar:"الإشارات والأنظمة", topics:[
    "Calculus (derivatives/integrals)","Complex Numbers","Trigonometric Functions","Differential Equations basics","Function transformations"
  ]},
  "EEE4 3253": { name:"Advanced Programming Languages", ar:"لغات برمجة حديثة", topics:[
    "OOP Principles","Data Types & Collections","Exception Handling","Functions & Recursion","Basic algorithms"
  ]},
  "EEE4 3254": { name:"Data Structures and Algorithms", ar:"هياكل البيانات والخوارزميات", topics:[
    "Arrays","Linked Lists","Recursion","Big-O Notation","Pointers/References","Basic Sorting"
  ]},
  "ACD0 4264": { name:"Differential Equations & Linear Algebra", ar:"المعادلات التفاضلية والجبر الخطي", topics:[
    "Calculus I & II","Integration Techniques","Matrices basics","Systems of Equations","Vectors"
  ]},
  "EEE3 3498": { name:"Communications Systems", ar:"أنظمة الاتصالات", topics:[
    "Signals & Systems","Fourier basics","Modulation concepts","Probability basics","Frequency domain"
  ]},
  "EEE4 3455": { name:"Software Engineering", ar:"هندسة البرمجيات", topics:[
    "OOP Concepts","UML Diagrams basics","SDLC overview","Requirements thinking","Version Control (Git) basics"
  ]},
  "EEE4 3595": { name:"Web Application Development", ar:"تطوير تطبيقات الويب", topics:[
    "HTML & CSS","JavaScript basics","Client-Server model","HTTP basics","Programming fundamentals"
  ]},
  "EEE4 3360": { name:"Database Systems", ar:"نظم قواعد البيانات", topics:[
    "Data Structures basics","Set Theory basics","ER concepts","Relational model intro","Basic SQL SELECT"
  ]},
  "EEE4 3358": { name:"Computer Architecture", ar:"معمارية الحاسوب", topics:[
    "Digital Logic","Number Systems","Boolean Algebra","Registers & Memory basics","Assembly concepts"
  ]},
  "EEE4 3465": { name:"Computer Interfacing Techniques", ar:"تقنيات ربط الحاسوب", topics:[
    "Digital Logic","Microprocessor basics","I/O ports concept","Binary & Hex","Basic electronics"
  ]},
  "EEE4 3584": { name:"Smart Phone Applications", ar:"تطبيقات الهواتف الذكية", topics:[
    "OOP (Java/Kotlin)","UI/UX basics","Event-driven programming","Basic APIs","XML basics"
  ]},
  "EEE4 3596": { name:"Advanced Web Application Development", ar:"تطوير تطبيقات الويب المتقدمة", topics:[
    "Web fundamentals (HTML/CSS/JS)","DOM manipulation","REST APIs","Async/Promises","A frontend framework intro"
  ]},
  "EEE4 3356": { name:"Operating System", ar:"نظم التشغيل", topics:[
    "Processes & Threads","CPU Scheduling","Memory Management Basics","Synchronization Concepts","Computer Architecture basics"
  ]},
  "EEE4 3461": { name:"Advanced Database Systems", ar:"نظم قواعد البيانات المتقدمة", topics:[
    "Relational model","SQL (joins/subqueries)","Normalization","Indexing basics","Transactions intro"
  ]},
  "EEE4 3469": { name:"Computer Networks", ar:"شبكات الحاسوب", topics:[
    "OSI Model","TCP/IP Basics","IP Addressing","Subnetting","Binary & Number Systems"
  ]},
  "EEE4 3491": { name:"Artificial Intelligence", ar:"الذكاء الاصطناعي", topics:[
    "Data Structures","Search Algorithms","Probability & Statistics","Linear Algebra basics","Python basics"
  ]},
  "EEE6 3585": { name:"Embedded Systems", ar:"الأنظمة المدمجة", topics:[
    "Microprocessors","Digital Logic","C Programming","Interfacing basics","Number Systems"
  ]},
  "EEE4 3490": { name:"Internet of Things", ar:"إنترنت الأشياء", topics:[
    "Networking basics","Embedded Systems intro","Sensors & Actuators","Basic programming","Protocols (MQTT/HTTP)"
  ]},
  "EEE4 3576": { name:"Information Security and Networks", ar:"أمن المعلومات والشبكات", topics:[
    "Computer Networks","OSI & TCP/IP","Cryptography basics","Number Theory basics","Common attack concepts"
  ]},
  "EEE0 3555": { name:"Graduation Project", ar:"مشروع التخرج", topics:[
    "Research Methods","Project Management basics","Documentation & Report writing","Presentation skills","Your project's technical stack"
  ]},
  "CMP0 3315": { name:"Digital Skills", ar:"المهارات الرقمية", topics:[
    "أساسيات الحاسوب","نظام التشغيل Windows","حزمة Office (Word/Excel/PowerPoint)","أساسيات الإنترنت والبحث","السلامة الرقمية"
  ]},
  "ACD0 3158": { name:"Arabic Language", ar:"اللغة العربية", topics:[
    "قواعد النحو الأساسية","الإملاء والترقيم","التعبير الكتابي","الفهم والاستيعاب","أساسيات البلاغة"
  ]},
  "ACD0 3150": { name:"Calculus I", ar:"تفاضل وتكامل ١", topics:[
    "الدوال وأنواعها","النهايات والاتصال","المشتقات وقواعدها","تطبيقات المشتقة","أساسيات المثلثات والجبر"
  ]},
  "ACD0 3151": { name:"Calculus II", ar:"تفاضل وتكامل ٢", topics:[
    "التكامل غير المحدود","طرق التكامل","التكامل المحدود وتطبيقاته","المتسلسلات","تفاضل وتكامل ١ (متطلب)"
  ]},
  "ACD0 4159": { name:"General Physics", ar:"فيزياء عامة", topics:[
    "وحدات القياس والتحويلات","المتجهات","الحركة والقوى (نيوتن)","الشغل والطاقة","الكهرباء الأساسية"
  ]},
  "ACD0 3159": { name:"English Language I", ar:"اللغة الإنجليزية ١", topics:[
    "Basic Grammar (tenses)","Common Vocabulary","Reading Comprehension","Sentence Structure","Listening basics"
  ]},
  "ACD0 3157": { name:"English Language II", ar:"اللغة الإنجليزية ٢", topics:[
    "English Language I","Advanced Tenses","Paragraph Writing","Technical Vocabulary","Reading & Summarizing"
  ]},
  "EEE0 1151": { name:"Introduction to Engineering", ar:"مقدمة في الهندسة", topics:[
    "تخصصات الهندسة","أخلاقيات المهنة","التفكير الهندسي وحل المشكلات","وحدات القياس","مهارات أساسية بالحاسوب"
  ]},
  "MEE0 1151": { name:"Engineering Workshop", ar:"المشغل الهندسي", topics:[
    "قواعد السلامة في المشغل","الأدوات اليدوية","القياس والمعايرة","قراءة الرسومات البسيطة","أساسيات التصنيع"
  ]},
  "ACD0 3262": { name:"Islamic Culture", ar:"الثقافة الإسلامية", topics:[
    "مصادر التشريع الإسلامي","العقيدة الإسلامية","الأخلاق والمعاملات","الإسلام والحضارة","القضايا المعاصرة"
  ]},
  "EEE1 1253": { name:"Electrical Circuits Lab", ar:"مختبر الدوائر الكهربائية", topics:[
    "الدوائر الكهربائية (النظري)","استخدام الملتيميتر","قوانين أوم وكيرشوف","توصيل الدوائر عملياً","قواعد السلامة الكهربائية"
  ]},
  "EEE1 1255": { name:"Electronics Lab", ar:"مختبر الإلكترونيات", topics:[
    "الإلكترونيات (النظري)","الدايود والترانزستور","بناء دوائر على Breadboard","استخدام الأوسيلوسكوب","تحليل النتائج"
  ]},
  "EEE0 3352": { name:"Technology and Society", ar:"التكنولوجيا والمجتمع", topics:[
    "أثر التكنولوجيا في المجتمع","أخلاقيات التقنية","الخصوصية والأمن","الفجوة الرقمية","مهارات البحث والكتابة"
  ]},
  "EEE1 1151": { name:"Electronic Computer Aided Design", ar:"التصميم الإلكتروني بمساعدة الحاسوب", topics:[
    "أساسيات الإلكترونيات","مكونات الدوائر","برامج المحاكاة (Proteus/Multisim)","قراءة المخططات","تصميم PCB مبسّط"
  ]},
  "BUS0 3451": { name:"Management Principles", ar:"مبادئ الإدارة", topics:[
    "وظائف الإدارة","التخطيط والتنظيم","القيادة والتحفيز","اتخاذ القرار","إدارة الوقت والفريق"
  ]},
  "ACD0 3266": { name:"Probability and Statistics", ar:"نظرية الاحتمالات والإحصاء", topics:[
    "الإحصاء الوصفي","الاحتمالات الأساسية","التوزيعات الاحتمالية","المتغيرات العشوائية","تفاضل وتكامل (متطلب)"
  ]},
  "ACD0 3370": { name:"Palestine Issue", ar:"قضية فلسطين", topics:[
    "الجذور التاريخية للقضية","المحطات السياسية الرئيسية","الجغرافيا السياسية","القانون الدولي","القراءة والتحليل"
  ]},
  "EEE1 1356": { name:"Digital Logic Fundamentals Lab", ar:"مختبر أساسيات المنطق الرقمي", topics:[
    "أساسيات المنطق الرقمي","البوابات المنطقية عملياً","جبر بول","بناء الدوائر المنطقية","استخدام رقائق IC"
  ]},
  "ACD0 3267": { name:"Numerical Analysis", ar:"التحليل العددي", topics:[
    "الخطأ والتقريب","حل المعادلات عددياً","الاستيفاء (Interpolation)","التكامل العددي","برمجة أساسية"
  ]},
  "EEE0 3200": { name:"Methods of Scientific Research", ar:"أساليب البحث العلمي", topics:[
    "خطوات البحث العلمي","صياغة المشكلة والفرضيات","جمع البيانات وتحليلها","التوثيق والاقتباس","كتابة التقرير البحثي"
  ]},
  "EEE0 1554": { name:"Introduction to Graduation Project", ar:"مقدمة في مشروع التخرج", topics:[
    "أساليب البحث العلمي (متطلب)","اختيار فكرة المشروع","دراسة الجدوى","التخطيط الزمني","كتابة المقترح (Proposal)"
  ]},

  // ===== المساقات الاختيارية =====
  "EEE4 3471": { name:"Advanced Web Development", ar:"تطوير مواقع الإنترنت المتقدمة", topics:[
    "HTML/CSS/JS المتقدمة","إطار عمل واجهات (React/Vue)","REST APIs","Node.js أساسيات","قواعد البيانات والمصادقة"
  ]},
  "EEE0 2580": { name:"Entrepreneurship and Freelancing", ar:"ريادة الأعمال والعمل الحر", topics:[
    "أساسيات ريادة الأعمال","دراسة السوق","نموذج العمل التجاري (BMC)","منصات العمل الحر","التسويق الذاتي والعقود"
  ]},
  "EEE4 3473": { name:"Advanced Smart Phone Applications", ar:"تطبيقات الهواتف الذكية المتقدمة", topics:[
    "تطبيقات الهواتف (المتطلب)","إدارة الحالة (State Management)","APIs وقواعد البيانات السحابية","الإشعارات والاستشعار","النشر على المتاجر"
  ]},
  "EEE4 3583": { name:"Advanced Topics in Programming Languages", ar:"موضوع متقدم في لغات البرمجة", topics:[
    "نظرية لغات البرمجة","أنماط اللغات (وظيفية/كائنية/منطقية)","المترجمات والمفسرات","إدارة الذاكرة","تحليل بناء الجملة"
  ]},
  "EEE2 3595": { name:"Digital Signal Processing", ar:"معالجة الإشارات الرقمية", topics:[
    "الإشارات والأنظمة (المتطلب)","تحويل فورييه (FFT)","الترشيح الرقمي (FIR/IIR)","العينات ونظرية نايكويست","الأعداد المركبة والجبر الخطي"
  ]},
  "EEE4 3582": { name:"Advanced Data Structures & Algorithms", ar:"خوارزميات وتراكيب بيانات متقدمة", topics:[
    "هياكل البيانات الأساسية","تحليل التعقيد Big-O","الأشجار والرسوم البيانية","البرمجة الديناميكية","الخوارزميات الجشعة"
  ]},
  "EEE4 3594": { name:"Special Topics in CSE", ar:"موضوعات مختارة في هندسة نظم الحاسوب", topics:[
    "أساسيات هندسة الحاسوب","القراءة البحثية","التقنيات الحديثة في المجال","مهارات العرض والتوثيق","العمل على مشروع تطبيقي"
  ]},
  "EEE6 3586": { name:"Digital Control Systems", ar:"أنظمة التحكم الرقمية", topics:[
    "الإشارات والأنظمة","المعادلات التفاضلية","تحويل Z","أنظمة التحكم بالتغذية الراجعة","المعالجات الدقيقة"
  ]},
  "EEE6 3576": { name:"Introduction to Robotics", ar:"مقدمة في علم الروبوت", topics:[
    "الأنظمة المدمجة","المتحكمات الدقيقة","الحساسات والمحركات","الحركية (Kinematics)","برمجة C/Python"
  ]},
  "EEE4 3574": { name:"Advanced Interfacing Techniques", ar:"تقنيات الربط المتقدمة", topics:[
    "تقنيات ربط الحاسوب (المتطلب)","بروتوكولات الاتصال (SPI/I2C/UART)","المعالجات الدقيقة","برمجة المنافذ","الأنظمة المدمجة"
  ]},
  "EEE6 3589": { name:"Industrial Automation", ar:"الأتمتة الصناعية", topics:[
    "أنظمة التحكم","المتحكم المنطقي القابل للبرمجة (PLC)","الحساسات الصناعية","SCADA","السلامة الصناعية"
  ]},
  "EEE4 3599": { name:"Machine Learning", ar:"تعلم الآلة", topics:[
    "الجبر الخطي والإحصاء","Python و NumPy/Pandas","التعلم المُوجّه وغير المُوجّه","الانحدار والتصنيف","تقييم النماذج"
  ]},
  "EEE6 3588": { name:"Neural Networks", ar:"الشبكات العصبية", topics:[
    "تعلم الآلة (المتطلب)","الجبر الخطي والتفاضل","الإدراك متعدد الطبقات (MLP)","الانتشار العكسي","أطر العمل (TensorFlow/PyTorch)"
  ]},
  "EEE6 3587": { name:"Fuzzy Logic Systems", ar:"الأنظمة المنطقية الضبابية", topics:[
    "المنطق الرقمي","نظرية المجموعات","المجموعات الضبابية","قواعد الاستدلال الضبابي","تطبيقات التحكم"
  ]},
  "EEE2 3596": { name:"Image Processing & Computer Vision", ar:"معالجة الصور ورؤية الحاسوب", topics:[
    "الجبر الخطي والمصفوفات","معالجة الإشارات الرقمية","تمثيل الصور والبكسل","الترشيح والتحويلات","OpenCV و Python"
  ]},
  "EEE4 3591": { name:"Computer Graphics & Animation", ar:"الرسم الحاسوبي والتحريك", topics:[
    "الجبر الخطي (المتجهات/المصفوفات)","الهندسة ثلاثية الأبعاد","خوارزميات الرسم","OpenGL/WebGL","التحويلات والإسقاط"
  ]},
  "EEE2 3580": { name:"Pattern Recognition", ar:"تمييز النماذج", topics:[
    "الاحتمالات والإحصاء","الجبر الخطي","استخلاص السمات (Features)","التصنيف والتجميع","تعلم الآلة الأساسي"
  ]},
  "EEE3 3597": { name:"Information & Coding Theory", ar:"نظرية المعلومات والترميز", topics:[
    "الاحتمالات","الأنظمة العددية","الإنتروبيا وقياس المعلومة","ترميز المصدر والقناة","كشف وتصحيح الأخطاء"
  ]},
  "EEE4 3577": { name:"Computer Systems Security & Cryptography", ar:"التشفير وأمن أنظمة الحاسوب", topics:[
    "شبكات الحاسوب","نظرية الأعداد الأساسية","التشفير المتماثل وغير المتماثل","الدوال الهاشية","بروتوكولات الأمان"
  ]},
  "EEE4 3593": { name:"Advanced Computer Networks", ar:"شبكات الحاسوب المتقدمة", topics:[
    "شبكات الحاسوب (المتطلب)","نموذج OSI و TCP/IP","التوجيه المتقدم","جودة الخدمة (QoS)","الشبكات المعرّفة برمجياً (SDN)"
  ]},
  "EEE4 3579": { name:"Distributed Systems Technologies", ar:"تقنيات الأنظمة الموزعة", topics:[
    "شبكات الحاسوب","نظم التشغيل","التزامن والتوازي","بروتوكولات التوافق","النسخ والتحمل للأعطال"
  ]},
  "EEE4 3586": { name:"Cloud Computing", ar:"الحوسبة السحابية", topics:[
    "شبكات الحاسوب","نظم التشغيل والمحاكاة الافتراضية","نماذج الخدمات (IaaS/PaaS/SaaS)","الحاويات (Docker)","موازنة الأحمال"
  ]},
  "EEE4 3573": { name:"Compiler Design", ar:"تصميم المترجمات", topics:[
    "لغات البرمجة","هياكل البيانات","التحليل المعجمي والنحوي","الأتمتة المنتهية والقواعد","توليد الشيفرة"
  ]},
  "EEE4 3590": { name:"Advanced Operating Systems", ar:"نظم التشغيل المتقدمة", topics:[
    "نظم التشغيل (المتطلب)","العمليات والخيوط","إدارة الذاكرة الافتراضية","التزامن والجدولة","الأنظمة الموزعة"
  ]},
};
