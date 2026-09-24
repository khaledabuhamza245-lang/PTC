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