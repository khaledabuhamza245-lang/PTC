# User management changes

Admin-only endpoints:

- `GET /api/v1/admin/users`
- `POST /api/v1/admin/users`
- `PUT /api/v1/admin/users/{user}`
- `DELETE /api/v1/admin/users/{user}`
- `PATCH /api/v1/admin/users/{user}/role`
- `PATCH /api/v1/admin/users/role-by-email`

Admins may edit/delete students and supervisors. Admin accounts are protected from the general edit/delete endpoints.
Login now creates an independent Sanctum token instead of deleting all previous web tokens.
