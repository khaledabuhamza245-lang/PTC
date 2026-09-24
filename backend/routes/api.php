<?php

use App\Http\Controllers\Api\V1\Student\AiChatController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CourseFileController;
use App\Http\Controllers\Api\V1\CoursePrepTopicController;
use App\Http\Controllers\Api\V1\CoursePrerequisiteController;
use App\Http\Controllers\Api\V1\CourseProgressController;
use App\Http\Controllers\Api\V1\CourseStructureController;
use App\Http\Controllers\Api\V1\ContentReportController;
use App\Http\Controllers\Api\V1\CourseTipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\MyCourseController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\TermController;
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Controllers\Api\V1\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\V1\Admin\TermController as AdminTermController;
use App\Http\Controllers\Api\V1\Staff\AnnouncementController as StaffAnnouncementController;
use App\Http\Controllers\Api\V1\Staff\CourseFileController as StaffCourseFileController;
use App\Http\Controllers\Api\V1\Staff\CourseStructureController as StaffCourseStructureController;
use App\Http\Controllers\Api\V1\Staff\ContentReportController as StaffContentReportController;
use App\Http\Controllers\Api\V1\Staff\CourseTipController as StaffCourseTipController;
use App\Http\Controllers\Api\V1\Staff\CourseController as StaffCourseController;
use App\Http\Controllers\Api\V1\Staff\DashboardController;
use App\Http\Controllers\Api\V1\Staff\StatsController;
use App\Http\Controllers\Api\V1\Staff\ToolController as StaffToolController;
use App\Http\Controllers\Api\V1\Staff\UploadController;
use App\Http\Controllers\Api\V1\Staff\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\TelegramUploadController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\ScheduleLectureController;
use App\Http\Controllers\Api\V1\AiAssistantController;
use App\Http\Controllers\Api\V1\GpaController;
 
Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok']);
 
    /*
     * تستدعيها خدمة "ping" مجانية خارجية كل دقيقة — بديل عملي عن ميزة
     * Cron Jobs غير المتاحة بخطة الاستضافة الحالية. الحماية برمز سرّي
     * بالرابط نفسه، لا بجلسة تسجيل دخول، لأن المستدعي هنا آلي لا طالب.
     */
    Route::get('/ai/process-pending', [AiAssistantController::class, 'processPending']);
        Route::get('/ai/provider-usage', [AiAssistantController::class, 'providerUsage']);
    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/google', [AuthController::class, 'google']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });
 
    /*
     * عام لا محميّ عمدًا: أكثر حالة يُحتاج فيها التواصل هي حالة من لا
     * يستطيع الدخول أصلًا — تسجيل متعثّر، أو رسالة إعادة تعيين لا تصل.
     */
    Route::post('/contact', ContactController::class)
        ->middleware('throttle:contact');
 
    Route::get('/program', ProgramController::class);
    Route::get('/terms', [TermController::class, 'index']);
 
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/search', [SearchController::class, 'index']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::get('/courses/{course}/files', [CourseFileController::class, 'index']);
    Route::get('/courses/{course}/structure', [CourseStructureController::class, 'show']);
    Route::get('/courses/{course}/prerequisites', [CoursePrerequisiteController::class, 'show']);
    Route::get('/courses/{course}/prep-topics', [CoursePrepTopicController::class, 'index']);
    Route::get('/files/{courseFile}/download', [CourseFileController::class, 'download']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/tools', [ToolController::class, 'index']);
 
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/student/ai/chat', [AiChatController::class, 'chat']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [ProfileController::class, 'show']);
        Route::patch('/me', [ProfileController::class, 'update']);
        Route::get(
    '/courses/{course}/telegram-upload',
    TelegramUploadController::class
);
 
        // حدّ خاصّ لا 'auth': مفتاح ذاك هو حقل email وهو غائب عن هذا
        // الطلب، فتقع كل الطلبات على مفتاح فارغ واحد يتقاسمه الجميع.
        Route::post('/me/password', [ProfileController::class, 'updatePassword'])
            ->middleware('throttle:password-change');
 
        Route::get('/me/plan-summary', [PlanController::class, 'summary']);
        Route::get('/me/enrollments', [PlanController::class, 'enrollments']);
        Route::get('/me/gpa', [GpaController::class, 'index']);
        Route::put('/me/gpa/{course}', [GpaController::class, 'upsert']);
        Route::delete('/me/gpa/{course}', [GpaController::class, 'destroy']);
        Route::delete('/me/gpa', [GpaController::class, 'clear']);
 
        Route::get('/my-courses', [MyCourseController::class, 'index']);
        Route::post('/my-courses/{course}', [MyCourseController::class, 'store']);
        Route::patch('/my-courses/{course}', [MyCourseController::class, 'update']);
        Route::delete('/my-courses/{course}', [MyCourseController::class, 'destroy']);
 
        Route::get('/notifications/summary', [NotificationController::class, 'summary']);
        Route::get('/notifications/recent', [NotificationController::class, 'recent']);
        Route::post('/notifications/mark-seen', [NotificationController::class, 'markSeen']);
 
        Route::get('/schedule', [ScheduleLectureController::class, 'index']);
        Route::post('/schedule', [ScheduleLectureController::class, 'store']);
        Route::delete('/schedule/clear', [ScheduleLectureController::class, 'clear']);
        Route::put('/schedule/{scheduleLecture}', [ScheduleLectureController::class, 'update']);
        Route::delete('/schedule/{scheduleLecture}', [ScheduleLectureController::class, 'destroy']);
 
        Route::post('/ai/attachments/upload', [AiAssistantController::class, 'uploadAttachment'])
            ->middleware('throttle:ai-upload');
        Route::post('/ai/attachments/start', [AiAssistantController::class, 'startAttachmentUpload'])
            ->middleware('throttle:ai-upload');
        Route::post('/ai/attachments/complete', [AiAssistantController::class, 'completeAttachmentUpload'])
            ->middleware('throttle:ai-upload');
        Route::delete('/ai/attachments/{aiAttachment}', [AiAssistantController::class, 'deleteAttachment'])
            ->middleware('throttle:ai-upload');
            
        Route::post('/ai/ask', [AiAssistantController::class, 'ask']);
        Route::get('/ai/result/{aiQuestion}', [AiAssistantController::class, 'result']);
        Route::get('/ai/conversations', [AiAssistantController::class, 'conversations']);
        Route::get('/ai/conversations/{aiConversation}', [AiAssistantController::class, 'conversationMessages']);
        Route::post('/ai/conversations/{aiConversation}/feedback', [AiAssistantController::class, 'feedback']);
        Route::get('/ai/usage', [AiAssistantController::class, 'usage']);
        Route::post('/ai/course-files/{courseFile}/summarize', [AiAssistantController::class, 'summarizeFile']);

        Route::get('/ai/usage', [AiAssistantController::class, 'usage']);
        Route::post('/ai/course-files/{courseFile}/summarize', [AiAssistantController::class, 'summarizeFile']);
        Route::get('/ai/usage', [AiAssistantController::class, 'usage']);
        Route::post('/ai/course-files/{courseFile}/summarize', [AiAssistantController::class, 'summarizeFile']);
 
        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::get('/favorites/ids', [FavoriteController::class, 'ids']);
        Route::post('/content/{courseFile}/favorite', [FavoriteController::class, 'store']);
        Route::delete('/content/{courseFile}/favorite', [FavoriteController::class, 'destroy']);
 
        Route::get('/progress', [CourseProgressController::class, 'index']);
        Route::get('/progress/{course}', [CourseProgressController::class, 'show']);
        Route::put('/progress/{course}', [CourseProgressController::class, 'update']);
        Route::put('/content/{courseFile}/completed', [CourseStructureController::class, 'setCompleted']);
 
        Route::get('/courses/{course}/tips', [CourseTipController::class, 'index']);
        Route::post('/courses/{course}/tips', [CourseTipController::class, 'store'])
            ->middleware('throttle:course-tips');
 
        Route::post('/content/{courseFile}/report', [ContentReportController::class, 'store'])
            ->middleware('throttle:content-reports');
 
        Route::post('/courses/{course}/report', [ContentReportController::class, 'storeForCourse'])
            ->middleware('throttle:content-reports');
 
        Route::middleware('role:admin,supervisor')->prefix('staff')->group(function () {
            Route::get('/dashboard', DashboardController::class);
            Route::get('/stats/signups', [StatsController::class, 'signups']);
            Route::get('/users', [UserController::class, 'index']);
 
            Route::post('/uploads/presign', [UploadController::class, 'presign']);
            Route::get('/course-files', [StaffCourseFileController::class, 'index']);
            Route::post('/course-files', [StaffCourseFileController::class, 'store']);
            Route::patch('/course-files/{courseFile}', [StaffCourseFileController::class, 'update']);
            Route::delete('/course-files/{courseFile}', [StaffCourseFileController::class, 'destroy']);
 
            Route::get('/course-tips', [StaffCourseTipController::class, 'index']);
            Route::get('/course-tips/unread-count', [StaffCourseTipController::class, 'unreadCount']);
            Route::get('/course-tips/recent', [StaffCourseTipController::class, 'recent']);
            Route::post('/course-tips/mark-seen', [StaffCourseTipController::class, 'markSeen']);
            Route::delete('/course-tips/{courseTip}', [StaffCourseTipController::class, 'destroy']);
 
            Route::get('/content-reports', [StaffContentReportController::class, 'index']);
            Route::get('/content-reports/unread-count', [StaffContentReportController::class, 'unreadCount']);
            Route::get('/content-reports/recent', [StaffContentReportController::class, 'recent']);
            Route::post('/content-reports/mark-seen', [StaffContentReportController::class, 'markSeen']);
            Route::post('/content-reports/{contentReport}/resolve', [StaffContentReportController::class, 'resolve']);
 
            Route::post('/courses', [StaffCourseController::class, 'store']);
 
            /*
             * قبل {course}: لولا ذلك لالتقط ربط النموذج كلمة "reorder"
             * مفتاحَ مساق ورَدّ 404 قبل أن يصل المسار إلى معالجه.
             */
            Route::put('/courses/reorder', [StaffCourseController::class, 'reorder']);
 
            Route::patch('/courses/{course}', [StaffCourseController::class, 'update']);
            Route::delete('/courses/{course}', [StaffCourseController::class, 'destroy']);
 
            Route::post('/courses/{course}/prerequisites', [CoursePrerequisiteController::class, 'store']);
            Route::delete(
                '/courses/{course}/prerequisites/{prerequisite}',
                [CoursePrerequisiteController::class, 'destroy'],
            );
            Route::put('/courses/{course}/prep-topics', [CoursePrepTopicController::class, 'replace']);
 
            Route::get('/courses/{course}/sections', [StaffCourseStructureController::class, 'sections']);
            Route::post('/courses/{course}/sections', [StaffCourseStructureController::class, 'storeSection']);
            Route::patch('/sections/{section}', [StaffCourseStructureController::class, 'updateSection']);
            Route::delete('/sections/{section}', [StaffCourseStructureController::class, 'deleteSection']);
            Route::post('/courses/{course}/units', [StaffCourseStructureController::class, 'storeUnit']);
            Route::post('/sections/{section}/units', [StaffCourseStructureController::class, 'storeUnitFromSection']);
            Route::patch('/units/{unit}', [StaffCourseStructureController::class, 'updateUnit']);
            Route::delete('/units/{unit}', [StaffCourseStructureController::class, 'deleteUnit']);
 
            Route::get('/announcements', [StaffAnnouncementController::class, 'index']);
            Route::post('/announcements', [StaffAnnouncementController::class, 'store']);
            Route::patch('/announcements/{announcement}', [StaffAnnouncementController::class, 'update']);
            Route::post('/announcements/{announcement}/remind', [StaffAnnouncementController::class, 'remind']);
            Route::delete('/announcements/{announcement}', [StaffAnnouncementController::class, 'destroy']);
 
            Route::get('/tools', [StaffToolController::class, 'index']);
            Route::post('/tools', [StaffToolController::class, 'store']);
            Route::patch('/tools/{tool}', [StaffToolController::class, 'update']);
            Route::delete('/tools/{tool}', [StaffToolController::class, 'destroy']);
        });
 
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            // سلة المحذوفات: حذف المساق صار ناعمًا، فلا بد من طريقة
            // لرؤية ما حُذف واسترجاعه. CoursePolicy تقصرهما على المدير.
            Route::get('/courses/trashed', [StaffCourseController::class, 'trashed']);
            Route::post('/courses/{key}/restore', [StaffCourseController::class, 'restore']);
 
            /*
             * الفصول والإعدادات للمدير وحده لا للطاقم: تغيير الفصل
             * الحالي أو رقم التخرّج يعيد حساب تقدّم كل طالب في الموقع
             * دفعة واحدة — أثر أوسع من تحرير محتوى مساق.
             */
            Route::get('/terms', [AdminTermController::class, 'index']);
            Route::post('/terms', [AdminTermController::class, 'store']);
            Route::patch('/terms/{term}', [AdminTermController::class, 'update']);
            Route::delete('/terms/{term}', [AdminTermController::class, 'destroy']);
 
            Route::get('/settings', [AdminSettingController::class, 'index']);
            Route::put('/settings', [AdminSettingController::class, 'update']);
 
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
            Route::patch('/users/role-by-email', [UserController::class, 'updateRoleByEmail']);
            Route::get('/clear-app-cache', function() {
                \Illuminate\Support\Facades\Artisan::call('cache:clear');
                return 'Cache cleared successfully!';
            });
        });
    });
});
 
