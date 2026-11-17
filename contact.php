<?php
require_once __DIR__ . '/includes/functions.php';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);

    if (!empty($name) && !empty($email) && !empty($message)) {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO contact_messages (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $phone, $subject, $message]);
        $success_msg = "Thank you for contacting Rajpriya! Your message has been sent successfully.";
    } else {
        $error_msg = "Please fill in all required fields.";
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation('Contact Us', ['Contact' => '']); ?>
  <p class="text-secondary mb-4">Have questions about bulk school orders, custom notebook prints, or deliveries? Reach out to us!</p>

  <div class="row g-5">
    <!-- Contact Form -->
    <div class="col-lg-7">
      <div class="bg-white p-4 rounded-3 border">
        <h4 class="fw-bold text-navy mb-3">Send Us a Message</h4>

        <?php if ($success_msg): ?>
          <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
          <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form action="contact.php" method="POST">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Your Name *</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email Address *</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Phone Number</label>
              <input type="tel" name="phone" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Subject *</label>
              <input type="text" name="subject" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Message *</label>
              <textarea name="message" rows="4" class="form-control" required></textarea>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-gold">Send Message <i class="bi bi-send ms-1"></i></button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Contact Info Card -->
    <div class="col-lg-5">
      <div class="bg-white p-4 rounded-3 border h-100">
        <h4 class="fw-bold text-navy mb-4">Contact Information</h4>
        
        <div class="d-flex align-items-start gap-3 mb-4">
          <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-4"><i class="bi bi-building"></i></div>
          <div>
            <h6 class="fw-bold text-navy mb-1">Manufacturer</h6>
            <p class="text-secondary small mb-0"><strong>Mfd. by:</strong><br>Prabu Trader, SALEM</p>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3 mb-4">
          <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-4"><i class="bi bi-telephone"></i></div>
          <div>
            <h6 class="fw-bold text-navy mb-1">Phone & Customer Care</h6>
            <p class="text-secondary small mb-1"><strong>Ph:</strong> 0427-7966137</p>
            <p class="text-secondary small mb-1"><strong>Cell:</strong> 94433 46472</p>
            <p class="text-secondary small mb-0"><strong>Customer Care:</strong> +91 94425 46472</p>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3 mb-4">
          <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-4 flex-shrink-0"><i class="bi bi-envelope"></i></div>
          <div style="min-width: 0;">
            <h6 class="fw-bold text-navy mb-1">Email</h6>
            <p class="text-secondary small mb-1 text-break"><strong>General:</strong> <a href="mailto:info@rajpriyanotebooks.com" class="text-secondary text-decoration-none">info@rajpriyanotebooks.com</a></p>
            <p class="text-secondary small mb-0 text-break"><strong>Sales:</strong> <a href="mailto:sales@rajpriyanotebooks.com" class="text-secondary text-decoration-none">sales@rajpriyanotebooks.com</a></p>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3 mb-0">
          <div class="bg-warm-ivory p-3 rounded-circle text-gold fs-4 flex-shrink-0"><i class="bi bi-globe"></i></div>
          <div style="min-width: 0;">
            <h6 class="fw-bold text-navy mb-1">Website</h6>
            <p class="text-secondary small mb-0 text-break"><a href="http://www.rajpriyanotebooks.com/" target="_blank" rel="noopener noreferrer" class="text-secondary text-decoration-none">www.rajpriyanotebooks.com</a></p>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
