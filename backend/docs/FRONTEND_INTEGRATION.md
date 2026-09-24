# Frontend Integration

## API configuration

Create one file in the frontend, for example `api-config.js`:

```js
const API_URL = 'http://localhost:8000/api/v1';
```

## Request helper

```js
async function api(path, options = {}) {
  const token = localStorage.getItem('ptc_token');

  const response = await fetch(API_URL + path, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  const body = await response.json();

  if (!response.ok) {
    throw new Error(body.message || 'Request failed');
  }

  return body;
}
```

## Login example

```js
const result = await api('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email, password }),
});

localStorage.setItem('ptc_token', result.data.token);
localStorage.setItem('ptc_user', JSON.stringify(result.data.user));
```

## Large file upload

```js
const presign = await api('/staff/uploads/presign', {
  method: 'POST',
  body: JSON.stringify({
    course_id: courseId,
    file_name: file.name,
    mime_type: file.type,
    size_bytes: file.size,
  }),
});

await fetch(presign.data.upload_url, {
  method: 'PUT',
  headers: presign.data.headers,
  body: file,
});

await api('/staff/course-files', {
  method: 'POST',
  body: JSON.stringify({
    course_id: courseId,
    title,
    kind: 'pdf',
    storage_disk: presign.data.storage_disk,
    storage_path: presign.data.storage_path,
    original_name: file.name,
    mime_type: file.type,
    size_bytes: file.size,
    visibility: 'public',
  }),
});
```

## Course identity

Use the unique `key` returned by the API. Do not use only the displayed `code`, because elective courses can have the same code `EEEX 35XX`.
