(function () {
  'use strict';

  async function resolveUrl(course) {
    if (!course) {
      throw new Error('بيانات المادة غير متوفرة.');
    }

    const ref =
      course.key ||
      course.code ||
      course.id;

    if (!ref) {
      throw new Error('تعذّر تحديد المادة.');
    }

    const url =
      await PTCAuth.getTelegramUploadLink(ref);

    if (!url) {
      throw new Error(
        'تعذّر إنشاء جلسة المشاركة.'
      );
    }

    return url;
  }

  async function open(course) {
    const url = await resolveUrl(course);

    window.open(
      url,
      '_blank',
      'noopener'
    );
  }

  async function openFrom(button, course) {
    if (!button) return;

    const original =
      button.innerHTML;

    /*
     * التبويب يُفتح هنا فورًا، وقت الضغطة نفسها — قبل أي انتظار شبكة.
     * المتصفح يربطه ببادرة المستخدم المباشرة فلا يحجبه ولا يؤخّره.
     *
     * ملاحظة مهمة: بلا noopener هنا عمدًا — معها window.open يُرجع
     * null دائمًا (هذا ما تنص عليه المواصفة)، فتضيع القدرة على توجيه
     * هذا التبويب لاحقًا، ويبقى فارغًا للأبد بينما يُفتح تبويب ثانٍ
     * منفصل بعد كل انتظار الشبكة — وهذا بالضبط ما كان يظهر للطالب.
     * الأمان مؤمَّن يدويًا بإفراغ opener فور الحصول على المرجع.
     */
    const pending =
      window.open(
        '',
        '_blank'
      );

    if (pending) {
      pending.opener = null;
    }

    try {
      button.disabled = true;

      button.innerHTML =
        '<i data-icon="loader"></i> جارٍ فتح تيليجرام...';

      const url =
        await resolveUrl(course);

      if (pending && !pending.closed) {
        pending.location.href = url;
      } else {
        /* إن مُنع فتح النافذة الفارغة لأي سبب، نحاول فتحًا مباشرًا كحل بديل. */
        window.open(
          url,
          '_blank',
          'noopener'
        );
      }

    } catch (error) {
      if (pending && !pending.closed) {
        pending.close();
      }

      console.error(error);

      if (
        typeof SiteDialog !== 'undefined' &&
        SiteDialog.message
      ) {
        await SiteDialog.message({
          title: 'تعذّر فتح تيليجرام',
          message:
            error?.message ||
            'حاول مرة أخرى.'
        });
      } else {
        alert(
          error?.message ||
          'تعذّر فتح تيليجرام.'
        );
      }

    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  window.PTCContribute = {
    open,
    openFrom
  };
})();