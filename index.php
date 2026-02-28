<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ICARE · School Clinic</title>
  <!-- clean, premium sans‑serif -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
  <!-- Font Awesome 6 (free) – premium icons only, no emoji -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: #ECEFF1;      /* light background */
      color: #191970;                  /* midnight text */
      line-height: 1.5;
      scroll-behavior: smooth;
    }

    /* container – clean max-width */
    .container {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 2rem;
    }

    /* ---------- TYPOGRAPHY & LINKS ---------- */
    a {
      text-decoration: none;
      color: inherit;
    }

    .section-title {
      font-size: 2.25rem;
      font-weight: 600;
      letter-spacing: -0.02em;
      margin-bottom: 3rem;
      position: relative;
      display: inline-block;
    }
    .section-title::after {
      content: '';
      position: absolute;
      bottom: -10px;
      left: 0;
      width: 70px;
      height: 3px;
      background-color: #191970;        /* accent line */
      border-radius: 2px;
    }

    /* ---------- BUTTONS (only two colours) ---------- */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.75rem 2rem;
      font-weight: 500;
      font-size: 1rem;
      border-radius: 40px;              /* soft pill */
      transition: all 0.2s ease;
      border: 1.5px solid transparent;
      cursor: pointer;
    }

    .btn-primary {
      background-color: #191970;
      color: #ECEFF1;
      box-shadow: 0 8px 20px rgba(25, 25, 112, 0.15);
    }
    .btn-primary:hover {
      background-color: #0f0f5c;
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(25, 25, 112, 0.25);
    }

    .btn-outline {
      background-color: transparent;
      border-color: #191970;
      color: #191970;
    }
    .btn-outline:hover {
      background-color: rgba(25, 25, 112, 0.04);
      border-color: #0f0f5c;
    }

    /* ---------- NAVIGATION (clean, no background) ---------- */
    .navbar {
      padding: 1.5rem 0;
      background-color: transparent;
      position: absolute;
      width: 100%;
      top: 0;
      left: 0;
      z-index: 10;
    }
    .navbar .container {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    /* logo + wordmark */
    .logo-wrapper {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .logo-img {
      width: 44px;
      height: 44px;
      object-fit: contain;
    }
    .logo-text {
      font-size: 1.8rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #191970;
      line-height: 1;
    }
    .logo-text span {
      font-weight: 300;
      font-size: 1rem;
      margin-left: 4px;
      letter-spacing: 0.3px;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 2.5rem;
      list-style: none;
    }
    .nav-links a {
      font-weight: 500;
      font-size: 1.05rem;
      border-bottom: 2px solid transparent;
      padding-bottom: 4px;
      transition: border-color 0.2s;
    }
    .nav-links a:hover {
      border-bottom-color: #191970;
    }
    /* login as outline, no register */
    .nav-links .btn-outline {
      padding: 0.5rem 1.8rem;
      margin-left: 0.5rem;
    }

    /* ---------- HERO ---------- */
    .hero {
      min-height: 100vh;
      display: flex;
      align-items: center;
      background-color: #ECEFF1;
      padding: 6rem 0 4rem;
    }
    .hero .container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 3rem;
      align-items: center;
    }

    .hero h1 {
      font-size: 3.5rem;
      font-weight: 700;
      line-height: 1.1;
      letter-spacing: -0.03em;
      color: #191970;
      margin-bottom: 1.5rem;
    }
    .hero h1 i {
      font-size: 3rem;
      margin-right: 6px;
      color: #191970;
    }

    .hero p {
      font-size: 1.2rem;
      color: #2a2a5e;
      margin-bottom: 2.5rem;
      max-width: 90%;
      font-weight: 400;
      opacity: 0.9;
    }

    .hero-buttons {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
    }

    /* image side – clean abstract illustration */
    .hero-visual {
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .premium-icon-group {
      background-color: rgba(25, 25, 112, 0.02);
      padding: 2rem;
      border-radius: 50% 50% 30% 70% / 40% 30% 70% 60%;
      box-shadow: 0 25px 40px -10px rgba(25, 25, 112, 0.2);
    }
    .premium-icon-group i {
      font-size: 5rem;
      color: #191970;
      margin: 0 1rem;
      opacity: 0.9;
      transition: transform 0.3s;
    }
    .premium-icon-group i:hover {
      transform: scale(1.05);
    }
    .premium-icon-group .fa-stethoscope {
      font-size: 6rem;
    }

    /* ---------- FEATURES (cards) ---------- */
    .features {
      padding: 5rem 0 6rem;
      background-color: #FFFFFF;  /* subtle white cards on light bg */
      color: #191970;
    }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 2.5rem;
      margin-top: 2rem;
    }
    .feature-card {
      background: #ECEFF1;          /* light grey card, midnight text */
      padding: 2.5rem 2rem;
      border-radius: 2rem;
      transition: all 0.2s;
      border: 1px solid rgba(25, 25, 112, 0.08);
      box-shadow: 0 10px 20px -8px rgba(25, 25, 112, 0.1);
    }
    .feature-card:hover {
      transform: translateY(-8px);
      border-color: #191970;
      box-shadow: 0 24px 32px -12px rgba(25, 25, 112, 0.2);
    }
    .feature-icon {
      font-size: 2.5rem;
      margin-bottom: 1.5rem;
      color: #191970;
    }
    .feature-card h3 {
      font-size: 1.6rem;
      font-weight: 600;
      margin-bottom: 0.75rem;
    }
    .feature-card p {
      color: #2d2d68;
      font-weight: 400;
    }

    /* ---------- ABOUT / CAMPUS (clean separation) ---------- */
    .about {
      padding: 5rem 0;
      background-color: #ECEFF1;
    }
    .about-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4rem;
      align-items: center;
    }
    .about-text p {
      font-size: 1.2rem;
      margin-bottom: 1.5rem;
      color: #20204d;
    }
    .about-text i {
      width: 28px;
      color: #191970;
      margin-right: 0.5rem;
    }
    .about-highlight {
      background: #ffffffd6;
      padding: 2rem;
      border-radius: 2rem;
      border: 1px solid rgba(25, 25, 112, 0.15);
    }
    .about-highlight li {
      list-style: none;
      margin: 1.2rem 0;
      display: flex;
      align-items: center;
      gap: 1rem;
      font-weight: 500;
    }
    .about-highlight i {
      font-size: 1.8rem;
      width: 2.2rem;
      color: #191970;
    }

    /* ---------- CONTACT ---------- */
    .contact {
      padding: 5rem 0;
      background-color: #FFFFFF;
    }
    .contact-container {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 3rem;
      background: #ECEFF1;
      padding: 3rem;
      border-radius: 3rem;
      border: 1px solid rgba(25,25,112,0.1);
    }
    .contact-item {
      display: flex;
      align-items: center;
      gap: 1rem;
      font-size: 1.2rem;
    }
    .contact-item i {
      font-size: 2rem;
      width: 2.5rem;
      color: #191970;
    }
    .contact-item span {
      font-weight: 500;
    }

    /* ---------- FOOTER (minimal) ---------- */
    .footer {
      padding: 2rem 0;
      background-color: #191970;
      color: #ECEFF1;
      text-align: center;
    }
    .footer p {
      font-weight: 300;
      letter-spacing: 0.3px;
    }
    .footer i {
      color: #ECEFF1;
      margin: 0 4px;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 800px) {
      .hero .container {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .hero p {
        max-width: 100%;
      }
      .hero-buttons {
        justify-content: center;
      }
      .section-title::after {
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
      }
      .section-title {
        display: block;
        text-align: center;
      }
      .navbar .container {
        flex-direction: column;
        gap: 1rem;
      }
      .nav-links {
        gap: 1.5rem;
        flex-wrap: wrap;
        justify-content: center;
      }
      .about-grid {
        grid-template-columns: 1fr;
      }
      .contact-container {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <!-- navigation – clean, only login (no register) -->
  <nav class="navbar">
    <div class="container">
      <div class="logo-wrapper">
        <!-- logo from assets/images/clinic.png -->
        <img src="assets/images/clinic.png" alt="ICARE logo" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <span class="logo-text">ICARE<span>clinic</span></span>
      </div>
      <ul class="nav-links">
        <li><a href="#home">Home</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#contact">Contact</a></li>
        <li><a href="login.php" class="btn-outline btn"><i class="fas fa-arrow-right-to-bracket"></i> Login</a></li>
        <!-- register is intentionally omitted -->
      </ul>
    </div>
  </nav>

  <!-- HERO section – 1/1 impact, only two colours -->
  <section id="home" class="hero">
    <div class="container">
      <div class="hero-content">
        <h1>
          <i class="fas fa-heart-pulse"></i> ICARE<br>school clinic
        </h1>
        <p>Dedicated health & wellness for grades 11 – 4th year college. A calm, private space for students who mean business.</p>
        <div class="hero-buttons">
          <a href="login.php" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Access portal</a>
          <a href="#features" class="btn btn-outline"><i class="fas fa-chevron-circle-down"></i> Discover</a>
        </div>
      </div>
      <div class="hero-visual">
        <div class="premium-icon-group">
          <i class="fas fa-stethoscope"></i>
          <i class="fas fa-capsules"></i>
          <i class="fas fa-heart"></i>
        </div>
      </div>
    </div>
  </section>

  <!-- FEATURES – all cards with premium icons only -->
  <section id="features" class="features">
    <div class="container">
      <h2 class="section-title">Everything under one roof</h2>
      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon"><i class="fas fa-notes-medical"></i></div>
          <h3>Health records</h3>
          <p>Secure digital charts, vaccination history, and physical exams — tailored for senior students.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fas fa-truck-medical"></i></div>
          <h3>Emergency response</h3>
          <p>Rapid assessment, parent contact, and referral coordination when minutes matter.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fas fa-file-prescription"></i></div>
          <h3>Clearances & certificates</h3>
          <p>Fit‑to‑return, sports clearances, and medical slips – all digitally signed.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fas fa-clock"></i></div>
          <h3>Dispensing & stock</h3>
          <p>Track medicines, supplies, and requests with low‑stock alerts – always in control.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ABOUT – with target group emphasis (grades 11–college) -->
  <section id="about" class="about">
    <div class="container about-grid">
      <div class="about-text">
        <h2 class="section-title" style="margin-bottom: 2rem;">For the driven</h2>
        <p><i class="fas fa-graduation-cap"></i> <strong>Grades 11 – 4th year college</strong> – we focus on young adults who need quick, no‑nonsense medical attention between classes, exams, and activities.</p>
        <p><i class="fas fa-location-dot"></i> Private clinic inside the campus, staffed by professionals who understand academic pressure.</p>
        <p><i class="fas fa-shield-heart"></i> ICARE isn’t just a name — it’s our promise: <span style="font-weight:600;">immediate, confidential, attentive care.</span></p>
      </div>
      <div class="about-highlight">
        <ul>
          <li><i class="fas fa-id-card"></i> Student portal integration</li>
          <li><i class="fas fa-file-shield"></i> Confidential records</li>
          <li><i class="fas fa-hand-holding-medical"></i> Physical & mental first aid</li>
          <li><i class="fas fa-flask"></i> In‑house basic lab (vision, vitals)</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- CONTACT – no emoji, only font awesome icons -->
  <section id="contact" class="contact">
    <div class="container">
      <h2 class="section-title">Reach us</h2>
      <div class="contact-container">
        <div class="contact-item">
          <i class="fas fa-map-pin"></i>
          <span>Main Bldg, Room 115 · University circle</span>
        </div>
        <div class="contact-item">
          <i class="fas fa-phone"></i>
          <span>(02) 8790 4213</span>
        </div>
        <div class="contact-item">
          <i class="fas fa-envelope"></i>
          <span>icare.clinic@edu.ph</span>
        </div>
        <div class="contact-item">
          <i class="fas fa-clock"></i>
          <span>M–F 7:30 AM – 5:30 PM</span>
        </div>
      </div>
    </div>
  </section>

  <!-- FOOTER (clean) -->
  <footer class="footer">
    <div class="container">
      <p><i class="fas fa-copyright"></i> 2026 ICARE · school clinic for grades 11–college. all rights reserved.</p>
    </div>
  </footer>

  <!-- subtle hover effect dummy for image fallback (if png missing, text shows) -->
  <script>
    (function() {
      // if logo fails to load, the text 'ICARE clinic' remains visible – no harm.
      const logoImg = document.querySelector('.logo-img');
      if (logoImg) {
        logoImg.addEventListener('error', function() {
          this.style.display = 'none';
          // the sibling .logo-text is already displayed
        });
      }
    })();
  </script>
</body>
</html>