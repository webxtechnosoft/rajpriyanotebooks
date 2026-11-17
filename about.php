<?php
require_once __DIR__ . '/includes/functions.php';

$logos = get_active_logos();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation('About Raj Priya Notebooks', ['About Us' => '']); ?>
  <div class="text-center mb-5 pb-lg-3">
    <!-- Integrated Brand Badge / Seal -->
    <div class="d-inline-flex align-items-center justify-content-center bg-white border px-3 py-2 rounded-pill shadow-sm mb-4" style="border-color: #E6DCB8 !important;">
      <img src="<?php echo htmlspecialchars($logos['secondary']); ?>" alt="RAJPRIYA SVK" style="max-height: 38px; width: auto; object-fit: contain;" class="me-2">
      <span class="fw-bold small text-uppercase text-navy" style="letter-spacing: 1.5px; font-size: 0.75rem;">Official Brand Story</span>
    </div>
    
    <h1 class="display-5 fw-bold text-navy mb-3">Our Legacy of Excellence</h1>
    <p class="text-secondary mx-auto fs-5" style="max-width: 680px; line-height: 1.6;">
      Crafting premium school, college, and administrative notebooks designed to empower students, teachers, and professionals across India.
    </p>
  </div>

  <!-- Story & Vision: Perfectly Aligned Two-Column Section -->
  <div class="row align-items-stretch g-4 g-lg-5 mb-5">
    
    <!-- Left Column: Notebook Image Card -->
    <div class="col-lg-6 d-flex">
      <div class="bg-white p-3 p-md-4 rounded-4 border shadow-sm w-100 d-flex align-items-center justify-content-center" style="border-color: #E6DCB8 !important;">
        <img src="assets/images/banner_open_notebook.webp" alt="Rajpriya Craftsmanship" class="img-fluid rounded-3 w-100 h-100" style="object-fit: cover; max-height: 420px;">
      </div>
    </div>

    <!-- Right Column: Story Content & Stats -->
    <div class="col-lg-6 d-flex flex-column justify-content-between">
      <div class="bg-white p-4 p-md-5 rounded-4 border shadow-sm h-100 d-flex flex-column justify-content-between" style="border-color: #E6DCB8 !important;">
        <div>
          <div class="text-gold fw-bold small text-uppercase mb-2" style="letter-spacing: 1px;">
            <i class="bi bi-award me-1"></i> Quality is Our Motto
          </div>
          <h3 class="fw-bold text-navy mb-3">Why Every Great Idea Deserves Rajpriya</h3>
          <p class="text-secondary mb-3" style="font-size: 0.95rem; line-height: 1.6;">
            Established in <strong>1994</strong> by <strong>PRABU TRADER, Salem</strong>, <strong>RAJPRIYA NOTEBOOKS</strong> has been a trusted brand delivering quality school and college notebooks across India for over 25 years.
          </p>
          <p class="text-secondary mb-4" style="font-size: 0.95rem; line-height: 1.6;">
            Made from high-quality reputed mill papers, our wide range of notebooks includes Long Exercise Books, Student Exercise Books, Hard Bind Notebooks, Practical Records, Drawing Books, Registers, Ledgers, and Stationery. Every product is crafted with proper ruling, binding, finishing, and timely delivery.
          </p>
        </div>
        
        <!-- Balanced Stat Cards -->
        <div class="row g-3">
          <div class="col-6">
            <div class="p-3 rounded-3 text-center border" style="background-color: #FCF8EF; border-color: #E6DCB8 !important;">
              <h2 class="fw-bold text-gold mb-1 display-6">100%</h2>
              <div class="small text-navy fw-semibold">High-Grade Paper</div>
            </div>
          </div>
          <div class="col-6">
            <div class="p-3 rounded-3 text-center border" style="background-color: #FCF8EF; border-color: #E6DCB8 !important;">
              <h2 class="fw-bold text-gold mb-1 display-6">Millions+</h2>
              <div class="small text-navy fw-semibold">Happy Students</div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Three Core Pillars Section -->
  <div class="row g-4 pt-3">
    <div class="col-md-4">
      <div class="bg-white p-4 rounded-3 border shadow-sm text-center h-100" style="border-color: #E6DCB8 !important;">
        <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
          <i class="bi bi-file-earmark-text"></i>
        </div>
        <h5 class="fw-bold text-navy mb-2">Precision Ruling</h5>
        <p class="text-secondary small mb-0">Exact line spacing engineered to aid neat handwriting and mathematical calculations.</p>
      </div>
    </div>

    <div class="col-md-4">
      <div class="bg-white p-4 rounded-3 border shadow-sm text-center h-100" style="border-color: #E6DCB8 !important;">
        <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
          <i class="bi bi-journal-bookmark"></i>
        </div>
        <h5 class="fw-bold text-navy mb-2">Reinforced Binding</h5>
        <p class="text-secondary small mb-0">Heavy-duty Wire-O spiral and stitched binding built to withstand daily school and office use.</p>
      </div>
    </div>

    <div class="col-md-4">
      <div class="bg-white p-4 rounded-3 border shadow-sm text-center h-100" style="border-color: #E6DCB8 !important;">
        <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
          <i class="bi bi-shield-check"></i>
        </div>
        <h5 class="fw-bold text-navy mb-2">Eco-Friendly Paper</h5>
        <p class="text-secondary small mb-0">Sustainably sourced, ink-bleed-resistant white paper safe for all pen types.</p>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
