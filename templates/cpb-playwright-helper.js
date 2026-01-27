export function cpbAuthHeader(user, appPassword) {
  const token = Buffer.from(`${user}:${appPassword}`).toString('base64');
  return { Authorization: `Basic ${token}` };
}

export function buildCollegePayload(data) {
  return {
    post_type: 'college',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    excerpt: data.excerpt || '',
    status: data.status || 'publish',
    featured_media_url: data.featured_media_url || '',
    media: data.media || {},
    meta: data.meta || {},
    terms: data.terms || {},
    relations: data.relations || {},
    relations_create_missing: data.relations_create_missing ?? true
  };
}

export function buildCoursePayload(data) {
  return {
    post_type: 'course',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    excerpt: data.excerpt || '',
    status: data.status || 'publish',
    meta: data.meta || {},
    relations: data.relations || {},
    relations_create_missing: data.relations_create_missing ?? true
  };
}

export function buildExamPayload(data) {
  return {
    post_type: 'exam',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    excerpt: data.excerpt || '',
    status: data.status || 'publish',
    meta: data.meta || {},
    relations: data.relations || {},
    relations_create_missing: data.relations_create_missing ?? true
  };
}

export function buildStreamPayload(data) {
  return {
    post_type: 'stream',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    excerpt: data.excerpt || '',
    status: data.status || 'publish',
    meta: data.meta || {}
  };
}

export async function cpbImport(request, baseUrl, user, appPassword, items) {
  const res = await request.post(`${baseUrl}/wp-json/cpb/v1/sync/import`, {
    headers: {
      ...cpbAuthHeader(user, appPassword),
      'Content-Type': 'application/json'
    },
    data: { items }
  });
  const body = await res.json();
  return { res, body };
}

export async function cpbExport(request, baseUrl, user, appPassword, types = ['college','course','exam','stream']) {
  const res = await request.get(`${baseUrl}/wp-json/cpb/v1/sync/export?types=${types.join(',')}`, {
    headers: cpbAuthHeader(user, appPassword)
  });
  const body = await res.json();
  return { res, body };
}
