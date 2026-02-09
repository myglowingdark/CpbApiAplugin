# CPB College Import - Playwright Quick Start

## Overview
This plugin now supports ALL college CPT fields including facilities, additional info, and university departments. Use Playwright to scrape college websites and import complete college data.

## New Fields Supported

### Basic Information
- `_college_website_url` - Official website
- `_college_established_year` - Founding year

### Location Details
- `_college_location` - City/District
- `_college_pincode` - Postal code
- `_college_state` - State/Province
- `_college_country` - Country
- `_college_address_line` - Full address

### Fee Information
- `_college_fee_min` - Minimum annual fee
- `_college_fee_max` - Maximum annual fee
- `_college_fees_info` - Detailed fee structure (HTML)

### Additional Information (HTML Content)
- `_college_admission_info` - Admission process, eligibility, dates
- `_college_placement_info` - Placement stats, recruiters, packages

### Facilities (All HTML Content)
- `_college_facility_description` - General overview
- `_college_facility_boys_hostel`
- `_college_facility_girls_hostel`
- `_college_facility_medical_hospital`
- `_college_facility_gym`
- `_college_facility_library`
- `_college_facility_sports`
- `_college_facility_it_infrastructure`
- `_college_facility_cafeteria`
- `_college_facility_auditorium`
- `_college_facility_transport_facility`
- `_college_facility_alumni_associations`
- `_college_facility_wifi`
- `_college_facility_laboratories`
- `_college_facility_guest_room`
- `_college_facility_training_placement_cell`

### University Information
- `_college_is_university` - Set to "1" if it's a university
- `_college_university_departments` - Array of department objects
  ```json
  [
    {"name": "College of Engineering", "ownership": "Private", "courses": 15}
  ]
  ```

## Quick Start with Playwright

### 1. Install Dependencies
```bash
cd automationPlaywright
npm install
```

### 2. Set Up Authentication
Create Application Password in WordPress:
- Go to Users → Profile
- Scroll to "Application Passwords"
- Create new password
- Save it securely

### 3. Basic Import Example

```javascript
import { test } from '@playwright/test';
import { 
  cpbImport, 
  buildCompleteCollegePayload, 
  buildMinimalCollegePayload 
} from './templates/cpb-playwright-helper.js';

test('Import single college with all data', async ({ page, request }) => {
  const baseUrl = 'http://localhost:8888/cpb';
  const user = 'your-username';
  const appPassword = 'your-app-password';

  // Navigate to college website
  await page.goto('https://example-college.edu');

  // Scrape all data
  const collegeData = {
    slug: 'example-college',
    title: await page.locator('h1').textContent(),
    content: await page.locator('.about').innerHTML(),
    excerpt: await page.locator('.summary').textContent(),
    
    // Basic info
    website_url: 'https://example-college.edu',
    established_year: 1998,
    
    // Location
    location: 'Mumbai',
    state: 'Maharashtra',
    country: 'India',
    pincode: '400001',
    
    // Fees
    fee_min: '100000',
    fee_max: '250000',
    fees_info: '<p>Tuition: ₹1,50,000/year</p>',
    
    // Additional info
    admission_info: await page.locator('.admission').innerHTML(),
    placement_info: await page.locator('.placement').innerHTML(),
    
    // Media
    featured_media_url: await page.locator('.hero img').getAttribute('src'),
    logo_url: await page.locator('.logo img').getAttribute('src'),
    gallery_urls: await page.locator('.gallery img').evaluateAll(
      imgs => imgs.map(img => img.src)
    ),
    
    // Facilities
    facility_library: '<p>50,000+ books, digital resources</p>',
    facility_sports: '<p>Cricket, basketball, gym facilities</p>',
    facility_boys_hostel: '<p>500 rooms with modern amenities</p>',
    
    // Relations
    relations: {
      linked_courses: [
        { slug: 'btech', title: 'B.Tech', status: 'publish' }
      ]
    },
    relations_create_missing: true
  };

  // Build and import
  const payload = buildCompleteCollegePayload(collegeData);
  const { body } = await cpbImport(request, baseUrl, user, appPassword, [payload]);
  
  console.log('Import result:', body);
});
```

### 4. Minimal Import (Quick Start)

```javascript
const minimalData = {
  slug: 'quick-college',
  title: 'Quick College',
  content: '<p>Description...</p>',
  website_url: 'https://quick.edu',
  location: 'Delhi',
  state: 'Delhi',
  country: 'India'
};

const payload = buildMinimalCollegePayload(minimalData);
const { body } = await cpbImport(request, baseUrl, user, appPassword, [payload]);
```

### 5. Batch Import Multiple Colleges

```javascript
const colleges = [
  { slug: 'college-1', title: 'College 1', /* ... */ },
  { slug: 'college-2', title: 'College 2', /* ... */ },
  { slug: 'college-3', title: 'College 3', /* ... */ }
];

const items = colleges.map(data => buildCompleteCollegePayload(data));
const { body } = await cpbImport(request, baseUrl, user, appPassword, items);

console.log(`Created: ${body.created}, Updated: ${body.updated}, Errors: ${body.errors.length}`);
```

## Advanced Scraping Tips

### Scraping Facilities
```javascript
// Example: Scrape facilities from structured page
const facilities = await page.locator('.facility-item').evaluateAll(items => {
  return items.map(item => ({
    name: item.querySelector('h3').textContent,
    description: item.querySelector('.description').innerHTML
  }));
});

// Map to college fields
collegeData.facility_library = facilities.find(f => f.name.includes('Library'))?.description;
collegeData.facility_sports = facilities.find(f => f.name.includes('Sports'))?.description;
```

### Scraping University Departments
```javascript
const departments = await page.locator('.department-row').evaluateAll(rows => {
  return rows.map(row => ({
    name: row.querySelector('.dept-name').textContent,
    ownership: row.querySelector('.ownership').textContent,
    courses: parseInt(row.querySelector('.course-count').textContent)
  }));
});

collegeData.is_university = '1';
collegeData.university_departments = departments;
```

### Handling Image URLs
```javascript
// Convert relative URLs to absolute
const heroImage = await page.locator('.hero img').getAttribute('src');
collegeData.featured_media_url = new URL(heroImage, page.url()).href;

// Multiple gallery images
const galleryUrls = await page.locator('.gallery img').evaluateAll(imgs => 
  imgs.map(img => new URL(img.src, window.location.href).href)
);
collegeData.gallery_urls = galleryUrls;
```

## Testing Your Import

### Check Import Results
```javascript
const { body } = await cpbImport(request, baseUrl, user, appPassword, [payload]);

console.log('Results:', {
  created: body.created,
  updated: body.updated,
  skipped: body.skipped,
  errors: body.errors
});

// Check individual items
body.items.forEach(item => {
  console.log(`${item.action} - ${item.post_type} - ${item.slug} (ID: ${item.id})`);
});
```

### Verify on WordPress
After import, visit:
- Admin: `http://your-site/wp-admin/edit.php?post_type=college`
- Frontend: `http://your-site/college/your-college-slug/`
- API: `http://your-site/wp-json/cpb/v1/sync/export?types=college`

## Templates & Documentation

Download these from the plugin admin page (Tools → CPB CPT API Sync):
1. **JSON Templates** - Complete examples for all post types
2. **Playwright Helper** - JavaScript helper functions
3. **API Documentation** - Full API reference

## Common Patterns

### Pattern 1: Scrape List → Import All
```javascript
// Get all college links
const links = await page.locator('.college-link').evaluateAll(
  links => links.map(l => l.href)
);

// Visit each and import
for (const link of links) {
  await page.goto(link);
  const data = /* scrape college data */;
  const payload = buildCompleteCollegePayload(data);
  await cpbImport(request, baseUrl, user, appPassword, [payload]);
}
```

### Pattern 2: Incremental Updates
```javascript
// Export existing colleges
const { body: exportData } = await cpbExport(request, baseUrl, user, appPassword, ['college']);

// Update only changed data
const existingColleges = exportData.items.college;
const updates = existingColleges.map(college => ({
  slug: college.slug,
  title: college.title,
  // Add new facility data only
  meta: {
    ...college.meta,
    _college_facility_wifi: '<p>High-speed WiFi added</p>'
  }
}));

await cpbImport(request, baseUrl, user, appPassword, updates);
```

## Troubleshooting

### Authentication Issues
- Verify Application Password is correct
- Check user has 'edit_posts' capability
- Ensure HTTPS or local development exception

### Import Errors
- Check `body.errors` array for specific issues
- Verify required fields (slug or title)
- Ensure HTML is properly escaped in JSON

### Missing Data
- Check field names match exactly (e.g., `_college_facility_library`)
- Verify meta fields are in `meta` object, not root
- Media fields go in `media` object

## Support

For issues or questions:
1. Check plugin documentation page in WordPress admin
2. Review error messages in import response
3. Verify JSON payload structure against templates
4. Test with minimal payload first, then add fields incrementally
