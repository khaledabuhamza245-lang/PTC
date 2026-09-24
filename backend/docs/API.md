# API Endpoints

Base URL: `/api/v1`

## Public

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/health` | Health check |
| POST | `/auth/register` | Create student account |
| POST | `/auth/login` | Login |
| POST | `/auth/forgot-password` | Send reset link |
| POST | `/auth/reset-password` | Reset password |
| GET | `/courses` | List courses |
| GET | `/courses/{courseKey}` | Course details |
| GET | `/courses/{courseKey}/files` | Visible course files |
| GET | `/files/{fileId}/download` | Get download URL |
| GET | `/announcements` | Active announcements |

## Authenticated student

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/logout` | Logout |
| GET | `/me` | Current user |
| PATCH | `/me` | Update profile |
| GET | `/my-courses` | Student courses |
| POST | `/my-courses/{courseKey}` | Add course |
| DELETE | `/my-courses/{courseKey}` | Remove course |
| GET | `/progress` | All progress |
| GET | `/progress/{courseKey}` | One course progress |
| PUT | `/progress/{courseKey}` | Save progress |

## Staff

Requires `admin` or `supervisor`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/staff/dashboard` | Counts |
| GET | `/staff/users` | Paginated users |
| POST | `/staff/uploads/presign` | Direct upload URL |
| GET | `/staff/course-files` | Manage files |
| POST | `/staff/course-files` | Save file metadata |
| PATCH | `/staff/course-files/{id}` | Update file |
| DELETE | `/staff/course-files/{id}` | Delete file |
| GET | `/staff/announcements` | Manage announcements |
| POST | `/staff/announcements` | Create announcement |
| PATCH | `/staff/announcements/{id}` | Update announcement |
| DELETE | `/staff/announcements/{id}` | Delete announcement |

## Admin

| Method | Endpoint | Purpose |
|---|---|---|
| PATCH | `/admin/users/{id}/role` | Change user role |

## Authentication header

```http
Authorization: Bearer YOUR_TOKEN
Accept: application/json
```
