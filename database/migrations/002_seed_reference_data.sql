-- =====================================================================
-- 002 — Reference data required by the application (not demo data).
-- Values preserve the concepts of the legacy system:
--   * volunteer categories = the 4 options of tadwapage.php (first..fourth)
--   * facilities           = the 4 options of revers.php   (first..fourth)
--   * contact settings     = the phones/emails shown on the legacy homepage
-- =====================================================================

INSERT INTO event_types (code, name_ar, name_en) VALUES
  ('lecture',     'محاضرة',        'Lecture'),
  ('workshop',    'ورشة عمل',      'Workshop'),
  ('competition', 'مسابقة',        'Competition'),
  ('sports',      'رياضي',         'Sports'),
  ('cultural',    'ثقافي',         'Cultural'),
  ('exhibition',  'معرض',          'Exhibition'),
  ('other',       'أخرى',          'Other');

INSERT INTO volunteer_categories (code, name_ar, name_en, icon) VALUES
  ('event_management', 'إدارة الفعاليات', 'Event Management', 'bi-calendar2-check'),
  ('design',           'التصميم',         'Design',           'bi-palette'),
  ('translation',      'الترجمة',         'Translation',      'bi-translate'),
  ('tech_support',     'الدعم التقني',     'Technical Support','bi-pc-display');

INSERT INTO feedback_categories (code, name_ar, name_en) VALUES
  ('academic',   'الشؤون الأكاديمية', 'Academic affairs'),
  ('facilities', 'المرافق والمباني',  'Facilities & buildings'),
  ('services',   'الخدمات الطلابية',  'Student services'),
  ('events',     'الفعاليات والأنشطة', 'Events & activities'),
  ('it',         'الأنظمة التقنية',    'IT systems'),
  ('other',      'أخرى',              'Other');

INSERT INTO departments (name_ar, name_en) VALUES
  ('تقنية الحاسب',              'Computer Technology'),
  ('التقنية الكهربائية',         'Electrical Technology'),
  ('التقنية الإلكترونية',        'Electronics Technology'),
  ('التقنية الميكانيكية',        'Mechanical Technology'),
  ('التقنية الإدارية',           'Administrative Technology'),
  ('التقنية المدنية والمعمارية', 'Civil & Architectural Technology');

INSERT INTO facilities (code, name_ar, name_en, description_ar, description_en, location_ar, location_en, capacity, image_path) VALUES
  ('theater',
   'المسرح', 'Theater',
   'مسرح مجهز بالصوت والإضاءة لإقامة العروض والحفلات والمحاضرات الكبرى.',
   'A theater equipped with sound and lighting for performances, ceremonies and large lectures.',
   'المبنى الرئيسي', 'Main building', 400, 'assets/img/facilities/theater.jpg'),
  ('event_hall',
   'قاعة المناسبات والاحتفالات', 'Events & Ceremonies Hall',
   'قاعة متعددة الاستخدامات للمناسبات والاحتفالات والملتقيات.',
   'A multi-purpose hall for occasions, celebrations and forums.',
   'مبنى الأنشطة الطلابية', 'Student activities building', 250, 'assets/img/facilities/event-hall.jpg'),
  ('stadium',
   'الاستاد الرياضي', 'Sports Stadium',
   'استاد رياضي تبلغ مساحته حوالي 24,000 متر مربع.',
   'An outdoor sports stadium covering about 24,000 m².',
   'المنطقة الرياضية', 'Sports area', 2000, 'assets/img/facilities/stadium.jpeg'),
  ('gymnasium',
   'الصالة الرياضية', 'Gymnasium',
   'مبنى بمساحة 7,000 متر مربع يضم عدة ملاعب مختلفة ومسبحًا أولمبيًا.',
   'A 7,000 m² building with several courts and an Olympic swimming pool.',
   'المنطقة الرياضية', 'Sports area', 300, 'assets/img/facilities/gym.jpg');

INSERT INTO settings (`key`, value) VALUES
  ('site_name_ar',     'بوابة الكلية التقنية'),
  ('site_name_en',     'Technical College Portal'),
  ('contact_email',    'info@college.example'),
  ('contact_email_2',  'admin@college.example'),
  ('contact_phone',    '+966500000000'),
  ('contact_phone_2',  '+966110000000'),
  ('address_ar',       'الكلية التقنية — يُحدَّث العنوان من لوحة الإعدادات'),
  ('address_en',       'Technical College — update the address in Admin › Settings');
