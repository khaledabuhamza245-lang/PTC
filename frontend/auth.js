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