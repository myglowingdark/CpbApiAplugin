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

/**
 * Helper to build complete college with all fields
 * @param {Object} data - College data scraped from website
 * @returns {Object} Complete college payload
 */
export function buildCompleteCollegePayload(data) {
  const payload = {
    post_type: 'college',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    excerpt: data.excerpt || '',
    status: data.status || 'publish',
    
    // Featured image
    featured_media_url: data.featured_media_url || '',
    
    // Media (logo and gallery)
    media: {},
    
    // All meta fields
    meta: {
      _college_website_url: data.website_url || '',
      _college_established_year: data.established_year || 0,
      _college_location: data.location || '',
      _college_pincode: data.pincode || '',
      _college_state: data.state || '',
      _college_country: data.country || '',
      _college_address_line: data.address_line || '',
      _college_fee_min: data.fee_min || '',
      _college_fee_max: data.fee_max || '',
    },
    
    // Terms (taxonomies)
    terms: data.terms || {},
    
    // Relations
    relations: data.relations || {},
    relations_create_missing: data.relations_create_missing ?? true
  };
  
  // Add logo if provided
  if (data.logo_url) {
    payload.media._college_logo = { url: data.logo_url };
  }
  
  // Add gallery if provided
  if (data.gallery_urls && Array.isArray(data.gallery_urls)) {
    payload.media._college_gallery = data.gallery_urls.map(url => ({ url }));
  }
  
  // Add additional info fields if provided
  if (data.fees_info) payload.meta._college_fees_info = data.fees_info;
  if (data.admission_info) payload.meta._college_admission_info = data.admission_info;
  if (data.placement_info) payload.meta._college_placement_info = data.placement_info;
  
  // Add facility description
  if (data.facility_description) {
    payload.meta._college_facility_description = data.facility_description;
  }
  
  // Add individual facilities (only if they exist)
  const facilities = [
    'boys_hostel', 'girls_hostel', 'medical_hospital', 'gym', 'library',
    'sports', 'it_infrastructure', 'cafeteria', 'auditorium',
    'transport_facility', 'alumni_associations', 'wifi', 'laboratories',
    'guest_room', 'training_placement_cell'
  ];
  
  facilities.forEach(facility => {
    if (data[`facility_${facility}`]) {
      payload.meta[`_college_facility_${facility}`] = data[`facility_${facility}`];
    }
  });
  
  // Add university info if it's a university
  if (data.is_university) {
    payload.meta._college_is_university = '1';
    if (data.university_departments && Array.isArray(data.university_departments)) {
      payload.meta._college_university_departments = data.university_departments;
    }
  }
  
  return payload;
}

/**
 * Quick helper for minimal college data
 */
export function buildMinimalCollegePayload(data) {
  return {
    post_type: 'college',
    slug: data.slug,
    title: data.title,
    content: data.content || '',
    status: data.status || 'publish',
    meta: {
      _college_website_url: data.website_url || '',
      _college_location: data.location || '',
      _college_state: data.state || '',
      _college_country: data.country || ''
    }
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

/**
 * Example usage for scraping and importing a college with all fields
 */
export async function exampleCompleteCollegeImport(page, request, baseUrl, user, appPassword) {
  // Navigate to college website
  await page.goto('https://example-college.edu');
  
  // Scrape all college data
  const collegeData = {
    slug: 'example-college',
    title: await page.locator('h1.college-name').textContent(),
    content: await page.locator('.about-section').innerHTML(),
    excerpt: await page.locator('.short-description').textContent(),
    
    // Basic info
    website_url: 'https://example-college.edu',
    established_year: parseInt(await page.locator('.established').textContent()),
    
    // Location
    location: await page.locator('.city').textContent(),
    state: await page.locator('.state').textContent(),
    country: 'India',
    pincode: await page.locator('.pincode').textContent(),
    address_line: await page.locator('.address').textContent(),
    
    // Fees
    fee_min: await page.locator('.fee-min').textContent(),
    fee_max: await page.locator('.fee-max').textContent(),
    fees_info: await page.locator('.fees-details').innerHTML(),
    
    // Additional info
    admission_info: await page.locator('.admission-section').innerHTML(),
    placement_info: await page.locator('.placement-section').innerHTML(),
    
    // Media
    featured_media_url: await page.locator('.hero-image img').getAttribute('src'),
    logo_url: await page.locator('.college-logo img').getAttribute('src'),
    gallery_urls: await page.locator('.gallery img').evaluateAll(imgs => imgs.map(img => img.src)),
    
    // Facilities
    facility_description: await page.locator('.facilities-overview').innerHTML(),
    facility_boys_hostel: await page.locator('.hostel-boys').innerHTML(),
    facility_girls_hostel: await page.locator('.hostel-girls').innerHTML(),
    facility_library: await page.locator('.library-details').innerHTML(),
    facility_sports: await page.locator('.sports-details').innerHTML(),
    
    // Streams
    terms: {
      college_stream: ['engineering', 'medical']
    },
    
    // Linked courses
    relations: {
      linked_courses: [
        { slug: 'btech', title: 'B.Tech', status: 'publish' },
        { slug: 'mbbs', title: 'MBBS', status: 'publish' }
      ]
    },
    relations_create_missing: true
  };
  
  // Build and import
  const payload = buildCompleteCollegePayload(collegeData);
  const { res, body } = await cpbImport(request, baseUrl, user, appPassword, [payload]);
  
  console.log('Import result:', body);
  return body;
}

/**
 * Example for batch importing multiple colleges
 */
export async function exampleBatchImport(request, baseUrl, user, appPassword, collegesData) {
  const items = collegesData.map(data => buildCompleteCollegePayload(data));
  const { res, body } = await cpbImport(request, baseUrl, user, appPassword, items);
  console.log(`Imported ${body.created} colleges, updated ${body.updated}, errors: ${body.errors.length}`);
  return body;
}
