# Implementation Notes

- Structure is intentionally small and direct.
- Users and profile fields are stored in one `users` table.
- Roles are simple strings: `student`, `supervisor`, `admin`.
- Course progress remains JSON to match the current frontend logic.
- File binary data lives in S3/R2, not PostgreSQL.
- Course `key` is unique. Course `code` may repeat for electives.
- Supervisors manage content; only admins change roles.
