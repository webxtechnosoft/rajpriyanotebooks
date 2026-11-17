</main> <!-- End Main -->

<?php
$logos = isset($logos) ? $logos : get_active_logos();
?>
<!-- Main Footer -->
<footer class="main-footer">
  <div class="container">
    <div class="row g-4">
      
      <!-- Col 1: Brand Info -->
      <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
        <a href="index.php" class="d-inline-flex align-items-center gap-2 mb-3 text-decoration-none">
          <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="RAJPRIYA NOTEBOOKS" class="footer-logo mb-0">
          <div class="brand-text-lockup d-flex flex-column lh-1">
            <span class="brand-title-main text-white">RAJ PRIYA</span>
            <span class="brand-title-sub">NOTEBOOKS</span>
          </div>
        </a>
        <p class="mb-3" style="color: #A0ACC0; max-width: 300px;">
          Quality notebooks for a brighter tomorrow. Write. Learn. Grow. Every great idea deserves a perfect notebook.
        </p>
        <div class="d-flex gap-3 text-white fs-5">
          <a href="#" class="footer-social-link"><i class="bi bi-facebook"></i></a>
          <a href="#" class="footer-social-link"><i class="bi bi-instagram"></i></a>
          <a href="#" class="footer-social-link"><i class="bi bi-youtube"></i></a>
          <a href="#" class="footer-social-link"><i class="bi bi-twitter-x"></i></a>
        </div>
      </div>

      <!-- Col 2: Quick Links -->
      <div class="col-lg-2 col-md-6 mb-4 mb-lg-0">
        <h5 class="footer-heading">Quick Links</h5>
        <ul class="footer-links-list">
          <li><a href="index.php">Home</a></li>
          <li><a href="about.php">About Us</a></li>
          <li><a href="products.php">Products</a></li>
          <li><a href="reviews.php">Reviews</a></li>
          <li><a href="contact.php">Contact</a></li>
          <?php if (is_admin_logged_in()): ?>
            <li><a href="admin/index.php">Admin Dashboard</a></li>
            <li><a href="admin/logout.php">Admin Logout</a></li>
          <?php elseif (is_customer_logged_in()): ?>
            <li><a href="account.php">My Account</a></li>
            <li><a href="logout.php">Logout</a></li>
          <?php else: ?>
            <li><a href="login.php">Login / Register</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <!-- Col 3: Contact Details & Newsletter -->
      <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
        <h5 class="footer-heading">Contact Us</h5>
        <div class="mb-3 text-white-50 small footer-contact-list">
          <p class="mb-2 text-white-50"><strong class="text-white">Mfd. by:</strong> Prabu Trader, SALEM</p>
          <p class="mb-2 text-white-50">
            <i class="bi bi-whatsapp text-success me-2 align-middle"></i>WhatsApp: <a href="https://wa.me/919842746472" target="_blank" rel="noopener noreferrer" class="footer-contact-link text-white fw-semibold">98427 46472</a>
          </p>
          <p class="mb-2 text-white-50">
            <i class="bi bi-person-fill text-warning me-2 align-middle"></i>Admin: <a href="tel:9710415568" class="footer-contact-link">97104 15568</a>
          </p>
          <p class="mb-2 text-white-50">
            <i class="bi bi-briefcase-fill text-warning me-2 align-middle"></i>Sales: <a href="tel:9443346472" class="footer-contact-link">94433 46472</a>
          </p>
          <p class="mb-2 text-white-50">
            <i class="bi bi-envelope text-warning me-2 align-middle"></i>Email: <a href="mailto:info@rajpriyanotebooks.com" class="footer-contact-link">info@rajpriyanotebooks.com</a>
          </p>
          <p class="mb-3 text-white-50">
            <i class="bi bi-globe text-warning me-2 align-middle"></i>Website: <a href="http://www.rajpriyanotebooks.com/" target="_blank" rel="noopener noreferrer" class="footer-contact-link">www.rajpriyanotebooks.com</a>
          </p>
        </div>
        
        <form class="footer-newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing!');">
          <input type="email" class="footer-newsletter-input" placeholder="Enter your email" required>
          <button type="submit" class="footer-newsletter-btn"><i class="bi bi-send-fill"></i></button>
        </form>
      </div>

      <!-- Col 4: Google Maps Location -->
      <div class="col-lg-4 col-md-6 mb-4 mb-lg-0">
        <h5 class="footer-heading d-flex align-items-center">
          <i class="bi bi-geo-alt-fill text-warning me-2"></i>Our Location
        </h5>
        <div class="footer-map-container rounded-3 overflow-hidden shadow-sm border border-secondary border-opacity-25 mb-2">
          <iframe src="https://www.google.com/maps/embed?pb=!1m17!1m12!1m3!1d3907.935395785702!2d78.14645807505458!3d11.62795398857736!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m2!1m1!2zMTHCsDM3JzQwLjYiTiA3OMKwMDgnNTYuNSJF!5e0!3m2!1sen!2sin!4v1790918599571!5m2!1sen!2sin" 
                  width="100%" 
                  height="210" 
                  style="border:0;" 
                  allowfullscreen="" 
                  loading="lazy" 
                  referrerpolicy="strict-origin-when-cross-origin"
                  class="w-100 d-block"></iframe>
        </div>
        <div class="small text-white-50 d-flex align-items-center gap-1.5">
          <i class="bi bi-building text-warning"></i>
          <span><strong>Prabu Trader</strong>, SALEM, Tamil Nadu, India</span>
        </div>
      </div>

    </div>

    <!-- Footer Bottom -->
    <div class="footer-bottom d-flex justify-content-center align-items-center text-center">
      <div>
        &copy; <?php echo date('Y'); ?> Rajpriya Notebooks. All rights reserved.
        <span class="mx-2">|</span>
        <a href="https://www.webxtechnosoft.com/" class="text-decoration-none fw-semibold" style="color: var(--color-light-gold);" aria-label="Webx Technosoft website">Webx Technosoft</a>
      </div>
    </div>
  </div>
</footer>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js?v=<?php echo time(); ?>"></script>
</body>
</html>
