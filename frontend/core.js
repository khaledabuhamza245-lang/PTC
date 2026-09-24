/* core.js — تجميع يدوي لخمسة ملفات JS أساسية (api-config + api + auth + icons + app) */
/* بنفس ترتيب التنفيذ الأصلي بالضبط — آمن حسب فحص الاعتماديات (راجع خطوة ٧٨/٧٩ بالتوثيق) */
/* تنبيه: أي تعديل مستقبلي على أحد هذه الملفات الخمسة يتطلب إعادة توليد هذا الملف يدويًا قبل التسليم */

/* ===== api-config.js ===== */
const API_CONFIG = {
  baseUrl: '/index.php/api/v1'
};

const API_READY =
  typeof API_CONFIG.baseUrl === 'string' &&
  API_CONFIG.baseUrl.trim().length > 0;

/* ===== api.js ===== */
// طلبات API المشتركة.
const PTCApi = (function () {
  const TOKEN_KEY = 'ptc_api_token';

  function getToken() {
    try {
      const persistent = localStorage.getItem(TOKEN_KEY) || '';
      if (persistent) return persistent;

      // ترحيل الجلسات القديمة التي كانت محفوظة في sessionStorage.
      const legacy = sessionStorage.getItem(TOKEN_KEY) || '';
      if (legacy) {
        localStorage.setItem(TOKEN_KEY, legacy);
        sessionStorage.removeItem(TOKEN_KEY);
      }
      return legacy;
    } catch (_) {
      try {
        return sessionStorage.getItem(TOKEN_KEY) || '';
      } catch (_) {
        return '';
      }
    }
  }

  function setToken(token) {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token);
      else localStorage.removeItem(TOKEN_KEY);
      sessionStorage.removeItem(TOKEN_KEY);
    } catch (_) {
      try {
        if (token) sessionStorage.setItem(TOKEN_KEY, token);
        else sessionStorage.removeItem(TOKEN_KEY);
      } catch (_) {}
    }
  }

  function hasToken() {
    return !!getToken();
  }

  async function request(path, options = {}) {
    if (typeof API_READY === 'undefined' || !API_READY) {
      throw new Error('رابط الباك إند غير مضبوط.');
    }

    const method = options.method || 'GET';

    const headers = {
      Accept: 'application/json',
      ...(options.headers || {})
    };

    const token = getToken();
    let body = options.body;

    if (token && options.auth !== false) {
      headers.Authorization = `Bearer ${token}`;
    }

    if (body !== undefined && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }

    let response;
    const controller = new AbortController();
    const timeoutMs = Number(options.timeout || 15000);
    /* لازم نفرّق بين إلغاء تلقائي بسبب انتهاء المهلة وإلغاء صريح
       طلبه المستخدم (مثلًا زر "إلغاء" أثناء رفع ملف) — كلاهما يظهر
       بنفس AbortError بالضبط بواجهة fetch، لكن رسالة "انتهت المهلة"
       مضلِّلة تمامًا لو كان المستخدم هو من ألغى الطلب بنفسه. */
    let timedOut = false;
    const timeoutId = window.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);

    if (options.signal) {
      if (options.signal.aborted) controller.abort();
      else options.signal.addEventListener('abort', () => controller.abort(), { once: true });
    }

    try {
      response = await fetch(
         API_CONFIG.baseUrl.replace(/\/$/, '') + path,
        {
           method,
          headers,
          body,
            signal: controller.signal,
            cache: method.toUpperCase() === 'GET' ? 'no-store' : 'default'
        }
        );
    } catch (error) {
      if (error?.name === 'AbortError') {
        if (!timedOut) {
          const cancelError = new Error('تم إلغاء العملية.');
          cancelError.isCancelled = true;
          throw cancelError;
        }
        throw new Error('انتهت مهلة الاتصال بالخادم. حاول مرة أخرى.');
      }
      /*
       * فشل fetch نفسه (لا استجابة إطلاقًا) سببه شبه الأكيد انقطاع
       * الإنترنت عند الطالب، لا عطل بالخادم — الحالة الشائعة فعليًا
       * لمستخدم فعلي، لا لمطوّر يجرّب محليًا. status:0 يميّزه هنا عن
       * فشل مصادقة حقيقي (401) قادم من الخادم، حتى لا تُعامَل معاملة
       * واحدة عند refreshMe.
       */
      const offlineError = new Error('تعذّر الاتصال. تأكد من اتصالك بالإنترنت وحاول مرة أخرى.');
      offlineError.status = 0;
      offlineError.isNetworkError = true;
      window.dispatchEvent(new CustomEvent('ptc-network-error'));
      throw offlineError;
    } finally {
      window.clearTimeout(timeoutId);
    }

    window.dispatchEvent(new CustomEvent('ptc-network-ok'));

    let payload = {};

    try {
      payload = await response.json();
    } catch (_) {}

    if (response.status === 401 && token) {
      setToken('');

      window.dispatchEvent(
        new CustomEvent('ptc-api-unauthorized')
      );
    }

    if (!response.ok) {
      const firstError =
        Object.values(payload.errors || {})[0]?.[0];

      const rawMessage = firstError || payload.message;

      /*
       * بعض استجابات الخطأ (مثل abort_unless/404 القياسية بلارافيل،
       * أو أي خطأ سيرفر غير معالَج صراحة بالباك-إند) ترجع نصًّا
       * إنجليزيًا افتراضيًا مثل "Not Found." بدل رسالة عربية واضحة —
       * وكانت هذي الرسائل الخام تظهر للطالب حرفيًا وسط واجهة عربية
       * بالكامل. لو الرسالة القادمة من السيرفر لا تحوي أي حرف عربي،
       * نعتبرها غير مخصَّصة للعرض على الطالب ونستبدلها برسالة عربية
       * عامة (مع رمز الحالة للتوضيح)، بدل عرضها كما هي.
       */
      const hasArabicText = rawMessage && /[؀-ۿ]/.test(rawMessage);
      const message = hasArabicText
        ? rawMessage
        : `حدث خطأ في الطلب (رمز ${response.status}). حاول مرة أخرى.`;

      const error = new Error(message);
      error.status = response.status;
      error.errors = payload.errors || {};
      error.rawMessage = rawMessage || null;

      throw error;
    }

    return payload;
  }

  return {
    request,
    getToken,
    setToken,
    hasToken,

    get: (path, options = {}) =>
      request(path, options),

    post: (path, body, options = {}) =>
      request(path, {
        ...options,
        method: 'POST',
        body
      }),

    put: (path, body, options = {}) =>
      request(path, {
        ...options,
        method: 'PUT',
        body
      }),

    patch: (path, body, options = {}) =>
      request(path, {
        ...options,
        method: 'PATCH',
        body
      }),

    delete: (path, options = {}) =>
      request(path, {
        ...options,
        method: 'DELETE'
      })
  };
})();

/* ===== auth.js ===== */
// المصادقة والبيانات المشتركة مع Laravel API.
const PTCAuth = (function () {
  const USER_CACHE_KEY = 'ptc_auth_user';
  const COURSES_CACHE_KEY = 'ptc_courses_cache_v1';
  const COURSES_CACHE_TTL_MS = 5 * 60 * 1000;
 
  let user = null;
  let profile = null;
  let ready = false;
  let initPromise = null;
  let validationPromise = null;
  let validationTimer = null;
  let coursesCache = null;
  let coursesPromise = null;
  let myCourseRowsPromise = null;
  let recentNotificationsPromise = null;

  const enabled = () => typeof API_READY !== 'undefined' && API_READY;
  const enc = value => encodeURIComponent(String(value));
 
  function readCachedUser() {
    try {
      const raw = localStorage.getItem(USER_CACHE_KEY) || sessionStorage.getItem(USER_CACHE_KEY);
      if (!raw) return null;
      const cached = JSON.parse(raw);
      localStorage.setItem(USER_CACHE_KEY, raw);
      sessionStorage.removeItem(USER_CACHE_KEY);
      return cached;
    } catch (_) {
      return null;
    }
  }
 
  function writeCachedUser(value) {
    try {
      if (value) localStorage.setItem(USER_CACHE_KEY, JSON.stringify(value));
      else localStorage.removeItem(USER_CACHE_KEY);
      sessionStorage.removeItem(USER_CACHE_KEY);
    } catch (_) {
      try {
        if (value) sessionStorage.setItem(USER_CACHE_KEY, JSON.stringify(value));
        else sessionStorage.removeItem(USER_CACHE_KEY);
      } catch (_) {}
    }
  }
 
  function emit() {
    window.dispatchEvent(new CustomEvent('ptc-auth-change', {
      detail: { user, profile, ready }
    }));
  }
 
  function readCoursesCache() {
    try {
      const parsed = JSON.parse(localStorage.getItem(COURSES_CACHE_KEY) || 'null');
      if (!parsed || !Array.isArray(parsed.data) || Date.now() - Number(parsed.savedAt || 0) > COURSES_CACHE_TTL_MS) {
        return null;
      }
      return parsed.data;
    } catch (_) {
      return null;
    }
  }
 
  function writeCoursesCache(data) {
    try {
      localStorage.setItem(COURSES_CACHE_KEY, JSON.stringify({ savedAt: Date.now(), data }));
    } catch (_) {}
  }
 
  function clearCoursesCache() {
    coursesCache = null;
    try { localStorage.removeItem(COURSES_CACHE_KEY); } catch (_) {}
  }
 
  function setSession(data) {
    const token = data?.token || '';
    const authenticatedUser = data?.user || null;
 
    if (!token || !authenticatedUser) {
      throw new Error('استجابة تسجيل الدخول غير مكتملة.');
    }
 
    PTCApi.setToken(token);
    writeCachedUser(authenticatedUser);
    user = authenticatedUser;
    profile = authenticatedUser;
    ready = true;
    emit();
  }
 
  function clearSession() {
    user = null;
    profile = null;
    if (validationTimer) {
      if ('cancelIdleCallback' in window) window.cancelIdleCallback(validationTimer);
      window.clearTimeout(validationTimer);
    }
    validationTimer = null;
    writeCachedUser(null);
    PTCApi.setToken('');
    ready = true;
    emit();
  }
 
  async function refreshMe() {
    if (validationPromise) return validationPromise;
 
    validationPromise = (async () => {
      try {
        const response = await PTCApi.get('/me');
        user = response.data || null;
        profile = user;
        writeCachedUser(user);
        ready = true;
        emit();
        return user;
      } catch (error) {
        /*
         * ٤٠١ وحدها تعني أن الخادم رفض الرمز فعلًا — فسادُ جلسة حقيقي
         * يستحق تسجيل خروج. أي شيء آخر (خصوصًا انقطاع الإنترنت، الذي
         * لا يحمل status أصلًا كما يضبطه api.js الآن) ليس دليلًا على
         * شيء بشأن الجلسة، فمسحها هنا كان يُخرج الطالب فعليًا بسبب
         * انقطاع لحظي بالشبكة أثناء تحقّق خلفي، لا لأن جلسته انتهت.
         */
        if (error.status === 401) {
          clearSession();
        } else {
          console.error(error);
          ready = true;
          emit();
        }
        return user;
      } finally {
        validationPromise = null;
      }
    })();
 
    return validationPromise;
  }
 
  function scheduleValidation() {
    if (validationTimer || validationPromise) return;
    const run = () => {
      validationTimer = null;
      refreshMe().catch(error => console.error('تعذّر التحقق من الجلسة:', error));
    };
 
    if ('requestIdleCallback' in window) {
      validationTimer = window.requestIdleCallback(run, { timeout: 900 });
    } else {
      validationTimer = window.setTimeout(run, 250);
    }
  }
 
  async function init() {
    if (initPromise) return initPromise;
 
    initPromise = (async () => {
      if (!enabled()) {
        ready = true;
        emit();
        return null;
      }
 
      if (!PTCApi.getToken()) {
        user = null;
        profile = null;
        ready = true;
        writeCachedUser(null);
        emit();
        return null;
      }
 
      const cached = readCachedUser();
      if (cached) {
        user = cached;
        profile = cached;
        ready = true;
        emit();
        // اعرض الصفحة فورًا، ثم تحقق من صلاحية الرمز في وقت خامل حتى لا يتأخر أول رسم.
        scheduleValidation();
        return user;
      }
 
      return refreshMe();
    })();
 
    return initPromise;
  }
 
  async function requireAuth(loginPage = 'login.html') {
    try {
      await init();
      if (!user) {
        window.location.replace(loginPage);
        return false;
      }
      return true;
    } finally {
      document.documentElement.classList.remove('auth-checking');
    }
  }
 
  async function redirectIfAuthenticated(targetPage = 'index.html') {
    await init();
    if (user) {
      window.location.replace(targetPage);
      return true;
    }
    return false;
  }
 
  async function signUp({ email, password, firstName, fatherName, lastName, year, semester }) {
    const response = await PTCApi.post('/auth/register', {
      first_name: firstName,
      father_name: fatherName,
      last_name: lastName,
      email,
      password,
      password_confirmation: password,
      year: year ? Number(year) : null,
      semester: semester ? Number(semester) : null
    }, { auth: false });
 
    setSession(response.data);
    return response.data;
  }
 
  async function signIn(email, password) {
    const response = await PTCApi.post('/auth/login', { email, password }, { auth: false });
    setSession(response.data);
    return response.data;
  }
 
  async function signInWithGoogle(idToken) {
    const response = await PTCApi.post('/auth/google', { id_token: idToken }, { auth: false });
    setSession(response.data);
    return response.data;
  }
 
  async function signOut() {
    try {
      if (PTCApi.getToken()) await PTCApi.post('/auth/logout');
    } catch (error) {
      console.error(error);
    } finally {
      clearSession();
      window.location.replace('login.html');
    }
  }
 
  async function resetPassword(email) {
    return PTCApi.post('/auth/forgot-password', { email }, { auth: false });
  }
 
  /*
   * التأكيد يُمرَّر ولا يُشتقّ من كلمة السر نفسها. إرسال الحقل مساويًا
   * لأصله يجعل قاعدة confirmed على الخادم تمرّ دائمًا مهما كتب الطالب،
   * فيُحفظ خطأ مطبعي في كلمة سر جديدة ولا يُكتشف حتى تفشل أول محاولة
   * دخول — وقد صار مفتاح الإصلاح نفسه منتهي الصلاحية.
   */
  async function setNewPassword({ token, email, password, passwordConfirmation }) {
    return PTCApi.post('/auth/reset-password', {
      token,
      email,
      password,
      password_confirmation: passwordConfirmation === undefined
        ? password
        : passwordConfirmation
    }, { auth: false });
  }
 
  async function changePassword({ currentPassword, password, passwordConfirmation }) {
    return PTCApi.post('/me/password', {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation === undefined
        ? password
        : passwordConfirmation
    });
  }
 
  /*
   * يرجع الملف الشخصي ومعه عدد ما سجّله الخادم تلقائيًا — تغيير السنة
   * يسجّل مساقاتها الناقصة، والصفحة تخبر الطالب بذلك. العدد يأتي من
   * الخادم لا يُستنتج من فرق عدّ الحالات: الاستنتاج يصحّ اليوم ويكذب
   * أول ما يضيف مسارٌ آخر تسجيلًا.
   */
  async function updateProfile(fields) {
    const response = await PTCApi.patch('/me', fields);
    user = response.data;
    profile = user;
    writeCachedUser(user);
    emit();
    return { user: user };
  }
 
  async function getCourses(force = false) {
    if (!force && coursesCache) return coursesCache;
    if (!force) {
      const saved = readCoursesCache();
      if (saved) {
        coursesCache = saved;
        return coursesCache;
      }
    }
    /*
     * تجميع الطلبات المتزامنة: لو أكثر من جزء بالصفحة نادى getCourses()
     * بنفس اللحظة (قبل ما يوصل أول رد)، الكل بينتظر نفس الطلب بدل ما
     * كل واحد يطلق طلب شبكة منفصل لنفس البيانات بالضبط.
     */
    if (coursesPromise) return coursesPromise;
    coursesPromise = (async () => {
      try {
        const response = await PTCApi.get('/courses', { auth: false });
        coursesCache = response.data || [];
        writeCoursesCache(coursesCache);
        return coursesCache;
      } finally {
        coursesPromise = null;
      }
    })();
    return coursesPromise;
  }
 
  async function findCourse(ref) {
    const courses = await getCourses();
    return courses.find(course => course.key === ref) ||
      courses.find(course => course.code === ref) ||
      courses.find(course => course.key === `c_${String(ref).replace(/^c_/, '')}`) ||
      null;
  }
 
  async function normalizeCourseRef(ref) {
    const course = await findCourse(ref);
    return course?.key || ref;
  }
 
  async function myCourseRows() {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    /* نفس منطق تجميع الطلبات المتزامنة أعلاه، لمسار /my-courses. */
    if (myCourseRowsPromise) return myCourseRowsPromise;
    myCourseRowsPromise = (async () => {
      try {
        return (await PTCApi.get('/my-courses')).data || [];
      } finally {
        myCourseRowsPromise = null;
      }
    })();
    return myCourseRowsPromise;
  }
 
  const isCurrentEnrollment = course => {
    const status = course.pivot && course.pivot.status;
    return status !== 'completed' && status !== 'dropped';
  };
 
  async function getMyCourses() {
    return (await myCourseRows()).map(course => course.key);
  }
 
  async function getMyCourseLists() {
    const rows = await myCourseRows();
 
    return {
      all: rows.map(course => course.key),
      current: rows.filter(isCurrentEnrollment).map(course => course.key),
    };
  }
 
  async function addMyCourse(ref) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    await PTCApi.post(`/my-courses/${enc(key)}`);
    return getMyCourses();
  }
 
  async function removeMyCourse(ref) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    await PTCApi.delete(`/my-courses/${enc(key)}`);
    return getMyCourses();
  }
 
  /*
   * ─────────────────────── الخطة الأكاديمية ───────────────────────
   *
   * getCourses تبقى بلا حالة الطالب: حمولتها عامة ومخزَّنة خمس دقائق
   * في localStorage، فحشو حالة شخصية فيها يجعلها مرئية لمن يفتح
   * المتصفح بعده. الحالات تأتي من plan-summary وتُدمج عند الرسم.
   */
  async function getPlanSummary() {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const response = await PTCApi.get('/me/plan-summary');
    return response.data || null;
  }
 
  async function getEnrollments(term = '') {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const query = term ? `?term=${enc(term)}` : '';
    return (await PTCApi.get(`/me/enrollments${query}`)).data || [];
  }
 
  async function setCourseStatus(ref, status, extra = {}) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    const response = await PTCApi.patch(`/my-courses/${enc(key)}`, { status, ...extra });
    return response.data || null;
  }

  /*
   * ─────────────────── حاسبة المعدل التراكمي ───────────────────
   *
   * جدول مستقل تمامًا عن my_courses (راجع تعليق هجرة gpa_entries) —
   * علامة تقديرية هون لمساق مستقبلي أو فصل قديم ما بتأثّر إطلاقًا
   * على "مساقاتي الحالية" ولا على نسبة إنجاز الخطة الحقيقية.
   */
  async function getMyGpa() {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const response = await PTCApi.get('/me/gpa');
    return (response.data && response.data.grades) || {};
  }

  async function saveGpaGrade(ref, grade) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    return PTCApi.put(`/me/gpa/${enc(key)}`, { grade });
  }

  async function clearGpaGrade(ref) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    return PTCApi.delete(`/me/gpa/${enc(key)}`);
  }

  async function clearAllGpa() {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    return PTCApi.delete('/me/gpa');
  }
 
  const getProgram = async () => (await PTCApi.get('/program', { auth: false })).data || null;
  const getTerms = async () => await PTCApi.get('/terms', { auth: false });
 
  /*
   * رسالة «تواصل معنا». auth:false صراحةً لا سهوًا: المسار عام في الباك
   * لأن أكثر من يحتاج التواصل هو من لا يستطيع الدخول أصلًا، فلا يُرسَل
   * معه توكن حتى لو كان في التخزين.
   */
  const sendContactMessage = async payload =>
    await PTCApi.post('/contact', payload, { auth: false });
 
  const getCoursePrerequisites = async ref => {
    const key = await normalizeCourseRef(ref);
    return (await PTCApi.get(`/courses/${enc(key)}/prerequisites`, { auth: false })).data
      || { prerequisites: [], required_for: [] };
  };
 
  const getCoursePrepTopics = async ref => {
    const key = await normalizeCourseRef(ref);
    return (await PTCApi.get(`/courses/${enc(key)}/prep-topics`, { auth: false })).data || [];
  };
 
  async function getProgress(ref) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    const response = await PTCApi.get(`/progress/${enc(key)}`);
    return response.data || null;
  }
 
  async function saveProgress(ref, data) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    await PTCApi.put(`/progress/${enc(key)}`, { data });
  }
 
  async function getAllProgress() {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const response = await PTCApi.get('/progress');
    const output = {};
    (response.data || []).forEach(item => {
      if (item.course?.key) output[item.course.key] = item.data;
    });
    return output;
  }
 
  async function getCourseFiles(ref) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const key = await normalizeCourseRef(ref);
    const response = await PTCApi.get(`/courses/${enc(key)}/files`);
    return response.data || [];
  }
 
  async function getFileUrl(id) {
    if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
    const response = await PTCApi.get(`/files/${Number(id)}/download`);
    return response.data?.url || '';
  }
 
  async function openCourseFile(id) {
    const popup = window.open('', '_blank');
    try {
      const url = await getFileUrl(id);
      if (!url) throw new Error('رابط الملف غير متوفر.');
      if (popup) popup.location = url;
      else window.location.href = url;
    } catch (error) {
      if (popup) popup.close();
      throw error;
    }
  }
  
  async function getTelegramUploadLink(ref) {
  if (!user) {
    throw new Error('يجب تسجيل الدخول أولاً.');
  }
 
  const key = await normalizeCourseRef(ref);
 
  const response = await PTCApi.get(
    `/courses/${enc(key)}/telegram-upload`
  );
 
  const url = response.data?.url || '';
 
  if (!url) {
    throw new Error('تعذّر الحصول على رابط بوت تيليجرام.');
  }
 
  return url;
}
 
async function getCourseTips(ref) {
  const key = await normalizeCourseRef(ref);
 
  const response = await PTCApi.get(
    `/courses/${enc(key)}/tips`
  );
 
  return response.data || [];
}
 
async function addCourseTip(ref, message) {
  if (!user) {
    throw new Error('يجب تسجيل الدخول أولاً.');
  }
 
  const key = await normalizeCourseRef(ref);
 
  const response = await PTCApi.post(
    `/courses/${enc(key)}/tips`,
    { message }
  );
 
  return response.data;
}
 
/* ── المفضّلة ──────────────────────────────────────────────────────── */
/* ── مساعد المذاكرة الذكي ──────────────────────────────────────────── */
async function askAssistant(message, courseName, conversationId, attachmentIds = []) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.post('/ai/ask', {
    message: message || null,
    course_name: courseName || null,
    conversation_id: conversationId || null,
    attachments: attachmentIds,
  });
}
 
async function startAiAttachmentUpload(file, signal) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');

  const formData = new FormData();
  formData.append('file', file);

  /* الرفع يمرّ بسيرفرنا أولًا (لا مباشرة لجوجل من المتصفح) — جوجل
     ترفض استقبال رفع ملفات من متصفح ويب مباشرة أساسًا (CORS)، فسيرفرنا
     يتولّى تمريره لها بالنيابة عن الطالب. مهلة أطول من الافتراضي لأن
     حجم الملف قد يستغرق وقتًا أطول من طلب عادي.
     signal اختياري — يسمح للواجهة بإلغاء الرفع فعليًا (زر "إلغاء" أثناء
     "جارٍ الرفع…") بدل الاكتفاء بإخفائه بصريًا مع بقاء الرفع شغّالًا
     بالخلفية. */
  const result = await PTCApi.post('/ai/attachments/upload', formData, { timeout: 90000, signal });
  return result.attachment;
}
 
/*
 * تُستدعى لما يشيل الطالب مرفقًا رفعه فعلًا (نجح الرفع) من قائمة
 * المرفقات المعلَّقة قبل ما يرسل السؤال — بلا هذا الاستدعاء كان
 * الملف يبقى محفوظًا على السيرفر (وعلى Gemini) رغم إنه اختفى من
 * واجهة الطالب، وكأنه "أُزيل" بينما هو ما زال موجودًا فعليًا حتى
 * ينتهي صلاحيته تلقائيًا. فشل صامت مقصود هنا (catch فارغ من المستدعي)
 * لأنه تنظيف خلفي فقط، لا يفترض يعطّل تجربة الطالب لو فشل لأي سبب.
 */
async function deleteAiAttachment(attachmentId) {
  if (!user) return;
  await PTCApi.request(`/ai/attachments/${Number(attachmentId)}`, { method: 'DELETE' });
}

async function pollAssistantResult(questionId) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.get(`/ai/result/${Number(questionId)}`);
}
 
async function getAiConversations() {
  if (!user) return [];
  return (await PTCApi.get('/ai/conversations')).data || [];
}
 
async function getAiConversation(id) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.get(`/ai/conversations/${Number(id)}`);
}
 
async function sendAiFeedback(conversationId, messageIndex, feedback) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.post(`/ai/conversations/${Number(conversationId)}/feedback`, {
    message_index: messageIndex,
    feedback,
  });
}

async function getAiUsage() {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.get('/ai/usage');
}

async function summarizeCourseFile(courseFileId, mode = 'summary') {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.post(`/ai/course-files/${Number(courseFileId)}/summarize`, { mode });
}
 
/* ── جدولي الأسبوعي ────────────────────────────────────────────────── */
async function getSchedule() {
  if (!user) return [];
  return (await PTCApi.get('/schedule')).data || [];
}
 
async function createScheduleLecture(payload) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return (await PTCApi.post('/schedule', payload)).data;
}
 
async function updateScheduleLecture(id, payload) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return (await PTCApi.put(`/schedule/${Number(id)}`, payload)).data;
}
 
function deleteScheduleLecture(id) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.delete(`/schedule/${Number(id)}`);
}
 
function clearSchedule() {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.delete('/schedule/clear');
}
 
async function getFavorites() {
  if (!user) return [];
  return (await PTCApi.get('/favorites')).data || [];
}
 
async function getFavoriteIds() {
  if (!user) return [];
  return (await PTCApi.get('/favorites/ids')).data || [];
}
 
function addFavorite(contentId) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.post(`/content/${Number(contentId)}/favorite`, {});
}
 
function removeFavorite(contentId) {
  if (!user) throw new Error('يجب تسجيل الدخول أولاً.');
  return PTCApi.delete(`/content/${Number(contentId)}/favorite`);
}
 
async function reportContent(contentId, reason, note) {
  if (!user) {
    throw new Error('يجب تسجيل الدخول أولاً.');
  }
 
  const response = await PTCApi.post(
    `/content/${Number(contentId)}/report`,
    { reason, note: note || null }
  );
 
  return response.data;
}
 
async function submitCourseReport(ref, { courseFileId, reason, details }) {
  if (!user) {
    throw new Error('يجب تسجيل الدخول أولاً.');
  }
 
  const key = await normalizeCourseRef(ref);
 
  const response = await PTCApi.post(
    `/courses/${enc(key)}/report`,
    {
      course_file_id: courseFileId || null,
      reason,
      note: details || null
    }
  );
 
  return response.data;
}
 
/* ── جرس الإشعارات الموحّد (كل الأدوار) ───────────────────────────── */
async function getNotificationSummary() {
  if (!user) return 0;
  return (await PTCApi.get('/notifications/summary')).data?.count || 0;
}
 
async function getRecentNotifications() {
  if (!user) return { data: [], last_seen_at: null };
  /* نفس منطق تجميع الطلبات المتزامنة أعلاه، لمسار /notifications/recent. */
  if (recentNotificationsPromise) return recentNotificationsPromise;
  recentNotificationsPromise = (async () => {
    try {
      const response = await PTCApi.get('/notifications/recent');
      return { data: response.data || [], last_seen_at: response.last_seen_at || null };
    } finally {
      recentNotificationsPromise = null;
    }
  })();
  return recentNotificationsPromise;
}
 
function markNotificationsSeen() {
  return PTCApi.post('/notifications/mark-seen', {});
}
 
 
 
  /*
   * أُزيلت uploadNoteImage: كانت تستدعي POST /uploads/note-image وهو مسار
   * غير معرَّف في الباك اند إطلاقاً، فترد 404. والأسوأ أن journey.html كان
   * يسلكه للمستخدم المسجَّل فقط ويترك الزائر على المسار المحلي العامل —
   * أي أن تسجيل الدخول كان يُعطّل إدراج الصور بدل أن يحسّنه.
   *
   * لا يوجد تخزين ملفات عامل في المشروع (FILESYSTEM_DISK=local وتدفّق
   * presign معطّل)، فالصور تُدمج محلياً بحدّ حجم. إن بُني مسار رفع حقيقي
   * لاحقاً، تُعاد الدالة هنا وتُضاف إلى كائن التصدير في آخر الملف —
   * وهو الموضع الذي نُسي فيه createUnitInSection فصارت ميتة.
   */
 
  async function migrateLocalToCloud() {
    return { courses: 0, progress: 0, failed: 0 };
  }
 
  const getCourseStructure = async ref => {
    const key = await normalizeCourseRef(ref);
    return (await PTCApi.get(`/courses/${enc(key)}/structure`)).data;
  };
  const setContentCompleted = async (id, completed) => (await PTCApi.put(`/content/${Number(id)}/completed`, { completed })).data;
  const getCourseSections = async ref =>
  (await PTCApi.get(`/staff/courses/${enc(ref)}/sections`)).data || {
    sections: [],
    orphan_units: [],
    units: [],
    general_sections: []
  };
 
const createSection = (ref, data) =>
  PTCApi.post(`/staff/courses/${enc(ref)}/sections`, data);
 
const updateSection = (id, data) =>
  PTCApi.patch(`/staff/sections/${Number(id)}`, data);
 
const deleteSection = id =>
  PTCApi.delete(`/staff/sections/${Number(id)}`);
 
const createUnit = (ref, data) =>
  PTCApi.post(`/staff/courses/${enc(ref)}/units`, data);
 
const createUnitInSection = (sectionId, data) =>
  PTCApi.post(
    `/staff/sections/${Number(sectionId)}/units`,
    data
  );
 
const updateUnit = (id, data) =>
  PTCApi.patch(`/staff/units/${Number(id)}`, data);
 
const deleteUnit = id =>
  PTCApi.delete(`/staff/units/${Number(id)}`);
  const createCourse = async data => {
    const response = await PTCApi.post('/staff/courses', data);
    clearCoursesCache();
    return response;
  };
  const updateCourse = async (ref, data) => {
    const response = await PTCApi.patch(`/staff/courses/${enc(ref)}`, data);
    clearCoursesCache();
    return response;
  };
  const deleteCourse = async ref => {
    const response = await PTCApi.delete(`/staff/courses/${enc(ref)}`);
    clearCoursesCache();
    return response;
  };
 
  /*
   * إعادة ترتيب فصل كامل في طلب واحد.
   *
   * السحبة تحرّك موضع كل ما بين المصدر والهدف، فطلبٌ لكل مادة يعني
   * عشرة طلبات متتابعة على استضافة بطابور متزامن — والخادم يحفظها
   * داخل transaction فلا يبقى نصف ترتيب.
   */
  const reorderCourses = async keys => {
    const response = await PTCApi.put('/staff/courses/reorder', { keys: keys });
    clearCoursesCache();
    return response;
  };
 
  const getPrerequisites = async ref =>
    (await PTCApi.get(`/courses/${enc(ref)}/prerequisites`)).data;
 
  const addPrerequisite = (ref, data) =>
    PTCApi.post(`/staff/courses/${enc(ref)}/prerequisites`, data);
 
  const removePrerequisite = (ref, id) =>
    PTCApi.delete(`/staff/courses/${enc(ref)}/prerequisites/${Number(id)}`);
 
  /*
   * سلة المحذوفات — للمدير وحده (CoursePolicy::viewTrashed و ::restore).
   * حذف المساق ناعم، فالسجل يبقى ومعه تسجيلات الطلاب وتقدّمهم.
   * لولا هاتان الدالتان لما عرف المدير أن الاسترجاع ممكن أصلًا.
   */
  const getTrashedCourses = async () => (await PTCApi.get('/admin/courses/trashed')).data;
 
  const restoreCourse = async ref => {
    const response = await PTCApi.post(`/admin/courses/${enc(ref)}/restore`);
    clearCoursesCache();
    return response;
  };
 
  const getDashboard = async () => (await PTCApi.get('/staff/dashboard')).data;
  const getStaffFiles = async (courseId = '', page = 1) =>
    PTCApi.get(`/staff/course-files?per_page=10&page=${page}${courseId ? `&course_id=${courseId}` : ''}`);
  const createCourseFile = data => PTCApi.post('/staff/course-files', data);
  const updateCourseFile = (id, data) => PTCApi.patch(`/staff/course-files/${Number(id)}`, data);
  const deleteCourseFile = id => PTCApi.delete(`/staff/course-files/${Number(id)}`);
  /*
   * البحث يُمرَّر للخادم لا يُرشَّح في المتصفح: الجدول مُرقَّم، فترشيح
   * الصفحة الحاضرة يبحث في عشرة صفوف لا في المستخدمين كلهم —
   * ويعطي «لا نتائج» عن اسم موجود في الصفحة التالية.
   */
  const getUsers = async (page = 1, search = '') =>
    PTCApi.get(`/staff/users?per_page=10&page=${page}${search ? `&search=${encodeURIComponent(search)}` : ''}`);
 
  const getSignupStats = async (days = 30) =>
    (await PTCApi.get(`/staff/stats/signups?days=${Number(days) || 30}`)).data;
  const getStaffAnnouncements = async (page = 1) => PTCApi.get(`/staff/announcements?per_page=25&page=${page}`);
  const createAnnouncement = data => PTCApi.post('/staff/announcements', data);
  const deleteAnnouncement = id => PTCApi.delete(`/staff/announcements/${Number(id)}`);
  const remindAnnouncement = id => PTCApi.post(`/staff/announcements/${Number(id)}/remind`, {});
  const getStaffCourseTips = async (page = 1, courseKey = '') =>
    PTCApi.get(`/staff/course-tips?per_page=25&page=${page}${courseKey ? `&course=${encodeURIComponent(courseKey)}` : ''}`);
  const deleteCourseTip = id => PTCApi.delete(`/staff/course-tips/${Number(id)}`);
  const getUnreadTipCount = async () =>
    (await PTCApi.get('/staff/course-tips/unread-count')).data?.count || 0;
  const getRecentTips = async () =>
    (await PTCApi.get('/staff/course-tips/recent')).data || [];
  const markTipsSeen = () => PTCApi.post('/staff/course-tips/mark-seen', {});
  const getStaffContentReports = async (page = 1, courseKey = '') =>
    PTCApi.get(`/staff/content-reports?per_page=25&page=${page}${courseKey ? `&course=${encodeURIComponent(courseKey)}` : ''}`);
  const resolveContentReport = id => PTCApi.post(`/staff/content-reports/${Number(id)}/resolve`, {});
  const getUnreadReportCount = async () =>
    (await PTCApi.get('/staff/content-reports/unread-count')).data?.count || 0;
  const getRecentReports = async () =>
    (await PTCApi.get('/staff/content-reports/recent')).data || [];
  const markReportsSeen = () => PTCApi.post('/staff/content-reports/mark-seen', {});
  const getStaffTools = async (page = 1, query = '') =>
    PTCApi.get(`/staff/tools?per_page=25&page=${page}${query ? `&q=${encodeURIComponent(query)}` : ''}`);
  const createTool = data => PTCApi.post('/staff/tools', data);
  const updateTool = (id, data) => PTCApi.patch(`/staff/tools/${Number(id)}`, data);
  const deleteTool = id => PTCApi.delete(`/staff/tools/${Number(id)}`);
  const updateRoleByEmail = (email, role) => PTCApi.patch('/admin/users/role-by-email', { email, role });
 
  /*
   * التعديل بالمعرّف لا بالبريد: جدول الصلاحيات يعرض الصف ومعه معرّفه،
   * فلا حاجة لرحلة بحث بالبريد يمكن أن تخطئ حرفًا. والحمايتان — المدير
   * الرئيسي ومنع تخفيض النفس — في الخادم لا هنا.
   */
  const updateUserRole = (id, role) => PTCApi.patch(`/admin/users/${Number(id)}/role`, { role });
  const createUser = data => PTCApi.post('/admin/users', data);
  const updateUser = (id, data) => PTCApi.put(`/admin/users/${Number(id)}`, data);
  const deleteUser = id => PTCApi.delete(`/admin/users/${Number(id)}`);
 
  /*
   * الإعدادات والفصول — لم يكن لهذه الستة مستدعٍ واحد في الواجهة.
   *
   * ستّة مسارات قائمة في الخادم بلا زرّ يصل إليها، فكان العميل بعد
   * التسليم لا يستطيع **تقديم الفصل الحالي** كل فصل دراسي، ولا تعديل
   * ساعات التخرّج ولا سقف الاختياريات، إلا بالكتابة في القاعدة مباشرة.
   * وتعليقات البذور نفسها تقول «يحرّرها الأدمن من اللوحة» — واللوحة لا
   * تحتوي على المكان الذي تصفه.
   *
   * getTerms أعلاه عامّ (auth:false) ويخدم قائمة الطالب؛ وهذه إدارية
   * تحت admin وترى الفصول كلها بحقولها الكاملة.
   */
  const getAdminSettings = async () => (await PTCApi.get('/admin/settings')).data;
  const saveAdminSettings = settings => PTCApi.put('/admin/settings', { settings });
 
  const getAdminTerms = async () => (await PTCApi.get('/admin/terms')).data;
  const createTerm = data => PTCApi.post('/admin/terms', data);
  const updateTerm = (id, data) => PTCApi.patch(`/admin/terms/${Number(id)}`, data);
  const deleteTerm = id => PTCApi.delete(`/admin/terms/${Number(id)}`);
 
  window.addEventListener('ptc-api-unauthorized', function () {
    clearSession();
    if (!location.pathname.endsWith('/login.html')) window.location.replace('login.html');
  });
 
  return {
    init, requireAuth, redirectIfAuthenticated, enabled, signUp, signIn, signInWithGoogle, signOut,
    resetPassword, setNewPassword, changePassword, updateProfile, getCourses, getMyCourses,
    getMyCourseLists,
    addMyCourse, removeMyCourse, getProgress, saveProgress, getAllProgress,
    getPlanSummary, getEnrollments, setCourseStatus, getProgram, getTerms,
    sendContactMessage,
    getCoursePrerequisites, getCoursePrepTopics,
    getCourseFiles, getFileUrl, openCourseFile, getTelegramUploadLink, migrateLocalToCloud,
    getCourseTips, addCourseTip,
    getCourseStructure, setContentCompleted, getCourseSections, createSection,
    updateSection, deleteSection, createUnit, createUnitInSection, updateUnit, deleteUnit, createCourse,
    updateCourse, deleteCourse, reorderCourses, getTrashedCourses, restoreCourse,
    getPrerequisites, addPrerequisite, removePrerequisite,
    getDashboard, getSignupStats, getStaffFiles, createCourseFile,
    updateCourseFile, deleteCourseFile, getUsers, getStaffAnnouncements,
    createAnnouncement, deleteAnnouncement, remindAnnouncement, updateRoleByEmail, updateUserRole,
    getStaffCourseTips, deleteCourseTip,
    getUnreadTipCount, getRecentTips, markTipsSeen,
    getNotificationSummary, getRecentNotifications, markNotificationsSeen,
    getFavorites, getFavoriteIds, addFavorite, removeFavorite,
    askAssistant, startAiAttachmentUpload, deleteAiAttachment, pollAssistantResult, getAiConversations, getAiConversation, sendAiFeedback,
    getAiUsage, summarizeCourseFile,
    getMyGpa, saveGpaGrade, clearGpaGrade, clearAllGpa,
    getSchedule, createScheduleLecture, updateScheduleLecture, deleteScheduleLecture, clearSchedule,
    reportContent, submitCourseReport, getStaffContentReports, resolveContentReport,
    getUnreadReportCount, getRecentReports, markReportsSeen,
    createUser, updateUser, deleteUser,
    getStaffTools, createTool, updateTool, deleteTool,
    getAdminSettings, saveAdminSettings,
    getAdminTerms, createTerm, updateTerm, deleteTerm,
    client: () => PTCApi,
    get user() { return user; },
    get profile() { return profile; },
    get isReady() { return ready; },
    isStaff: () => !!profile && ['admin', 'supervisor'].includes(profile.role),
    isAdmin: () => profile?.role === 'admin'
  };
})();
 
window.addEventListener('DOMContentLoaded', () => {
  // لا تبقَ أي صفحة مخفية إذا حصل خطأ أثناء فحص الجلسة.
  document.documentElement.classList.remove('auth-checking');
  PTCAuth.init().catch(error => console.error('تعذّر تهيئة الجلسة:', error));
});

/* ===== icons.js ===== */
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


/* ===== app.js ===== */

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
