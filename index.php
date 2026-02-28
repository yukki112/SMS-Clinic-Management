<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ICARE · academic health hub</title>
  <!-- strict color palette: #191970 (midnight) + #ECEFF1 (blue grey 50) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
  <!-- Font Awesome 6 (free) – premium sharp icons, no emoji -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: #ECEFF1;      /* soft background */
      color: #191970;                 /* deep midnight text */
      line-height: 1.5;
      scroll-behavior: smooth;
      overflow-x: hidden;
    }

    /* container */
    .container {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 2rem;
    }

    /* premium smooth elements */
    h1, h2, h3 {
      font-weight: 600;
      letter-spacing: -0.02em;
    }

    /* buttons – only two colors + transparency */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.75rem 2rem;
      border-radius: 40px;
      font-weight: 500;
      font-size: 1rem;
      text-decoration: none;
      transition: all 0.25s ease;
      border: 1.5px solid transparent;
      cursor: pointer;
      background: transparent;
    }

    .btn-primary {
      background: #191970;
      color: #ECEFF1;
      box-shadow: 0 8px 20px rgba(25, 25, 112, 0.2);
    }
    .btn-primary:hover {
      background: #2a2a9c;
      transform: translateY(-3px);
      box-shadow: 0 15px 30px rgba(25, 25, 112, 0.3);
    }

    .btn-outline {
      border-color: #191970;
      color: #191970;
      background: transparent;
    }
    .btn-outline:hover {
      background: rgba(25, 25, 112, 0.04);
      border-color: #191970;
      transform: translateY(-2px);
    }

    /* navbar */
    .navbar {
      padding: 1.25rem 0;
      position: fixed;
      width: 100%;
      top: 0;
      z-index: 100;
      background: rgba(236, 239, 241, 0.75); /* #ECEFF1 with transparency */
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(25, 25, 112, 0.1);
    }

    .nav-container {
      display: flex;
      align-items: center;
      justify-content: space-between;
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 2rem;
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
      filter: drop-shadow(0 2px 4px rgba(25,25,112,0.2));
    }
    .logo-text {
      font-size: 1.6rem;
      font-weight: 700;
      color: #191970;
      letter-spacing: -0.02em;
    }
    .logo-text span {
      font-weight: 300;
      color: #191970;
      opacity: 0.9;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 2.5rem;
      list-style: none;
    }
    .nav-links a {
      text-decoration: none;
      color: #191970;
      font-weight: 500;
      font-size: 1rem;
      transition: opacity 0.2s;
      opacity: 0.8;
    }
    .nav-links a:hover {
      opacity: 1;
    }

    /* remove register – only login */
    .nav-links .btn-outline {
      padding: 0.5rem 1.8rem;
      margin-left: 0.5rem;
    }

    /* hero */
    .hero {
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 7rem 0 4rem;
      background: #ECEFF1;
      position: relative;
      overflow: hidden;
    }

    .hero .container {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      align-items: center;
      gap: 3rem;
    }

    .hero h1 {
      font-size: 3.5rem;
      line-height: 1.2;
      margin-bottom: 1.5rem;
      color: #191970;
    }

    .hero-accent {
      background: rgba(25, 25, 112, 0.05);
      padding: 0.2rem 1rem;
      border-radius: 60px;
      display: inline-block;
      margin-bottom: 1.5rem;
      font-weight: 500;
      border: 1px solid rgba(25, 25, 112, 0.2);
      backdrop-filter: blur(4px);
    }

    .hero p {
      font-size: 1.2rem;
      margin-bottom: 2.5rem;
      color: #191970;
      opacity: 0.8;
      max-width: 90%;
    }

    .hero-buttons {
      display: flex;
      gap: 1.5rem;
      align-items: center;
    }

    /* floating elements (animation) */
    .floating-shape {
      position: absolute;
      width: 320px;
      height: 320px;
      border-radius: 64px;
      background: rgba(25, 25, 112, 0.03);
      bottom: -80px;
      right: -40px;
      transform: rotate(25deg);
      z-index: 0;
      border: 2px dashed rgba(25, 25, 112, 0.15);
      animation: float 18s infinite ease-in-out;
    }

    .floating-shape-two {
      width: 200px;
      height: 200px;
      background: rgba(25, 25, 112, 0.02);
      position: absolute;
      top: 20%;
      left: -60px;
      border-radius: 50%;
      border: 2px dotted rgba(25, 25, 112, 0.2);
      animation: float-reverse 22s infinite alternate;
    }

    @keyframes float {
      0% { transform: rotate(25deg) translateY(0) translateX(0); }
      50% { transform: rotate(30deg) translateY(-20px) translateX(15px); }
      100% { transform: rotate(25deg) translateY(0) translateX(0); }
    }
    @keyframes float-reverse {
      0% { transform: translateY(0) rotate(0deg); }
      100% { transform: translateY(-40px) rotate(10deg); }
    }

    /* right side visual — abstract health + logo presence */
    .hero-visual {
      position: relative;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .icon-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.8rem;
      position: relative;
      z-index: 5;
    }

    .icon-card {
      background: white;
      width: 120px;
      height: 120px;
      border-radius: 32px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      box-shadow: 0 20px 35px -8px rgba(25, 25, 112, 0.2);
      backdrop-filter: blur(4px);
      background: rgba(255, 255, 255, 0.75);
      border: 1px solid rgba(25, 25, 112, 0.15);
      transition: all 0.25s;
    }
    .icon-card i {
      font-size: 2.8rem;
      color: #191970;
    }
    .icon-card span {
      font-weight: 500;
      color: #191970;
    }
    .icon-card:hover {
      transform: scale(1.05) translateY(-6px);
      background: white;
    }

    /* features section */
    .section {
      padding: 6rem 0;
      background: #ECEFF1;
    }

    .section-title {
      font-size: 2.5rem;
      margin-bottom: 1rem;
      color: #191970;
      text-align: center;
    }

    .section-subhead {
      text-align: center;
      font-size: 1.2rem;
      max-width: 700px;
      margin: 0 auto 4rem;
      opacity: 0.75;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 2.5rem;
    }

    .feature-item {
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(10px);
      border-radius: 48px;
      padding: 2.5rem 2rem;
      border: 1px solid rgba(25, 25, 112, 0.15);
      transition: 0.2s;
      box-shadow: 0 15px 30px -12px rgba(25, 25, 112, 0.1);
    }
    .feature-item:hover {
      background: white;
      border-color: #191970;
    }

    .feature-icon-lg {
      width: 70px;
      height: 70px;
      background: #191970;
      border-radius: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 2rem;
    }
    .feature-icon-lg i {
      font-size: 2.5rem;
      color: #ECEFF1;
    }

    .feature-item h3 {
      font-size: 1.7rem;
      margin-bottom: 1rem;
    }
    .feature-item p {
      opacity: 0.75;
      font-weight: 400;
    }

    /* about + stats */
    .about-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      align-items: center;
      gap: 4rem;
    }

    .about-text h2 {
      font-size: 2.8rem;
      line-height: 1.2;
      margin-bottom: 1.5rem;
    }
    .about-text p {
      font-size: 1.15rem;
      opacity: 0.8;
      margin-bottom: 2rem;
    }

    .stat-cards {
      display: flex;
      gap: 2rem;
      margin-top: 2.5rem;
    }
    .stat-item {
      background: white;
      padding: 1.5rem 2rem;
      border-radius: 40px;
      text-align: center;
      min-width: 130px;
      border: 1px solid #19197020;
    }
    .stat-number {
      font-size: 2.5rem;
      font-weight: 700;
      color: #191970;
    }
    .stat-label {
      font-size: 1rem;
      opacity: 0.7;
    }

    .about-visual {
      background: rgba(25, 25, 112, 0.03);
      border-radius: 100px 40px 100px 40px;
      padding: 2.5rem;
      border: 1px dashed #19197050;
    }
    .about-visual i {
      font-size: 4rem;
      color: #191970;
      margin: 1rem;
    }

    /* contact */
    .contact-cards {
      display: flex;
      flex-wrap: wrap;
      gap: 2rem;
      justify-content: center;
      margin-top: 2rem;
    }
    .contact-card {
      background: white;
      padding: 2rem 2.5rem;
      border-radius: 60px;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex: 1 1 260px;
      border: 1px solid rgba(25,25,112,0.2);
      box-shadow: 0 4px 14px rgba(25,25,112,0.05);
    }
    .contact-card i {
      font-size: 2.2rem;
      color: #191970;
    }
    .contact-card span {
      font-weight: 600;
      font-size: 1.2rem;
    }

    /* footer */
    .footer {
      padding: 2.5rem 0;
      border-top: 1px solid rgba(25,25,112,0.15);
      background: #ECEFF1;
    }
    .footer .container {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .footer small {
      opacity: 0.6;
      font-weight: 400;
    }
    .footer-links {
      display: flex;
      gap: 2rem;
    }
    .footer-links a {
      color: #191970;
      text-decoration: none;
      opacity: 0.7;
      font-weight: 500;
    }

    /* responsive touches */
    @media (max-width: 800px) {
      .hero .container {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .hero p { max-width: 100%; }
      .hero-buttons { justify-content: center; }
      .about-grid { grid-template-columns: 1fr; }
      .nav-links { gap: 1rem; }
    }
    @media (max-width: 600px) {
      .nav-container { flex-direction: column; gap: 0.8rem; }
      .logo-text { font-size: 1.4rem; }
    }

    /* utility */
    .text-midnight { color: #191970; }
    .bg-midnight { background: #191970; color: #ECEFF1; }
    .pill-badge {
      background: #19197010;
      border-radius: 100px;
      padding: 0.4rem 1rem;
      font-weight: 500;
    }
    hr {
      border: 0.5px solid rgba(25,25,112,0.1);
      margin: 2rem 0;
    }
  </style>
</head>
<body>
  <!-- fixed navigation (register omitted) -->
  <nav class="navbar">
    <div class="nav-container">
      <div class="logo-wrapper">
        <!-- logo from assets/images/clinic.png (fallback if missing) -->
        <img src="assets/images/clinic.png" alt="ICARE" class="logo-img" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'44\' height=\'44\' viewBox=\'0 0 24 24\' fill=\'%23191970\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E';">
        <span class="logo-text">ICARE<span>clinic</span></span>
      </div>
      <ul class="nav-links">
        <li><a href="#home">Home</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#contact">Contact</a></li>
        <!-- only login – no register -->
        <li><a href="login.php" class="btn btn-outline"><i class="fas fa-arrow-right-to-bracket" style="font-size: 0.9rem;"></i> Login</a></li>
      </ul>
    </div>
  </nav>

  <!-- hero / home -->
  <section id="home" class="hero">
    <div class="floating-shape"></div>
    <div class="floating-shape-two"></div>
    <div class="container">
      <div class="hero-content">
        <div class="hero-accent">
          <i class="fas fa-stethoscope" style="margin-right: 0.4rem;"></i> grades 11 – 4th year
        </div>
        <h1>health,<br>cleared for <span style="border-bottom: 3px solid #191970;">academics</span></h1>
        <p>ICARE clinic — purpose‑built for college & senior high. Digital clearance, smart visits, and instant records, all within your school.</p>
        <div class="hero-buttons">
          <a href="login.php" class="btn btn-primary"><i class="fas fa-clinic-medical"></i> staff access</a>
          <a href="#features" class="btn btn-outline"><i class="fas fa-star"></i> explore tools</a>
        </div>
        <!-- subtle stats -->
        <div style="margin-top: 3rem; display: flex; gap: 2rem;">
          <div><i class="fas fa-check-circle" style="color: #191970;"></i> <strong>1.2k+</strong> <span style="opacity: 0.6;">students</span></div>
          <div><i class="fas fa-clock"></i> <strong>real‑time</strong> <span style="opacity: 0.6;">updates</span></div>
        </div>
      </div>

      <div class="hero-visual">
        <!-- icon grid (premium, no emoji) -->
        <div class="icon-grid">
          <div class="icon-card"><i class="fas fa-notes-medical"></i> <span>clearance</span></div>
          <div class="icon-card"><i class="fas fa-vial"></i> <span>check‑up</span></div>
          <div class="icon-card"><i class="fas fa-heart-pulse"></i> <span>vitals</span></div>
          <div class="icon-card"><i class="fas fa-shield-virus"></i> <span>immunize</span></div>
        </div>
        <!-- tiny floating badge with logo reassurance -->
        <div style="position: absolute; bottom: 0; right: 0; background: #191970; color: #ECEFF1; border-radius: 60px; padding: 0.5rem 1.2rem; font-weight: 500; font-size: 0.9rem; backdrop-filter: blur(4px);">
          <i class="fas fa-id-card"></i> ICARE · since 2026
        </div>
      </div>
    </div>
  </section>

  <!-- features section -->
  <section id="features" class="section">
    <div class="container">
      <h2 class="section-title">designed for <span style="background: #191970; color: #ECEFF1; padding: 0 1rem;">campus clinic</span></h2>
      <p class="section-subhead">Everything a higher education clinic needs — no clutter, just essential tools in midnight & soft grey.</p>

      <div class="features-grid">
        <div class="feature-item">
          <div class="feature-icon-lg"><i class="fas fa-file-prescription"></i></div>
          <h3>clearance hub</h3>
          <p>Digital fit‑to‑return, sports & event slips. Track request history, expiry, and print official forms.</p>
        </div>
        <div class="feature-item">
          <div class="feature-icon-lg"><i class="fas fa-truck-medical"></i></div>
          <h3>emergency & incidents</h3>
          <p>Log incidents, notify parents, record ambulance calls. Structured for immediate response.</p>
        </div>
        <div class="feature-item">
          <div class="feature-icon-lg"><i class="fas fa-capsules"></i></div>
          <h3>medicine stock</h3>
          <p>Track supplies, expiry, request approvals, and dispensing. Barcode‑ready item codes.</p>
        </div>
        <div class="feature-item">
          <div class="feature-icon-lg"><i class="fas fa-notes-medical"></i></div>
          <h3>visit & physical exams</h3>
          <p>Log visit history, vitals, deworming, vaccination — all connected to student ID.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- about + statistics (no register) -->
  <section id="about" class="section" style="padding-top: 0;">
    <div class="container">
      <div class="about-grid">
        <div class="about-text">
          <span class="pill-badge"><i class="fas fa-hospital-user"></i> exclusively 11–college</span>
          <h2>one clinic,<br>four academic years.</h2>
          <p>ICARE is built for the busiest school stage: from grade 11 to 4th year college. We combine clearance workflows, medical certificates, and parental notifications in a single, secure system — no patient modules, only student‑first design.</p>
          <div class="stat-cards">
            <div class="stat-item">
              <div class="stat-number">4</div>
              <div class="stat-label">year levels</div>
            </div>
            <div class="stat-item">
              <div class="stat-number">2.5k</div>
              <div class="stat-label">active students</div>
            </div>
            <div class="stat-item">
              <div class="stat-number">98%</div>
              <div class="stat-label">faster clearance</div>
            </div>
          </div>
        </div>
        <div class="about-visual">
          <!-- logo repeated + abstract -->
          <img src="assets/images/clinic.png" alt="ICARE badge" style="width: 90px; height: 90px; object-fit: contain; margin-bottom: 1rem;" onerror="this.style.display='none'">
          <i class="fas fa-hand-holding-heart"></i>
          <i class="fas fa-laptop-medical"></i>
          <i class="fas fa-clipboard-list"></i>
          <p style="margin-top: 1.5rem; font-weight: 500;"><i class="fas fa-check-circle" style="color: #191970;"></i> integrated with school ID system</p>
        </div>
      </div>
    </div>
  </section>

  <!-- contact (no register) -->
  <section id="contact" class="section" style="background: rgba(25,25,112,0.02);">
    <div class="container">
      <h2 class="section-title">reach ICARE clinic</h2>
      <p class="section-subhead">located at the main building · open 7am – 5pm, Mon–Fri</p>
      <div class="contact-cards">
        <div class="contact-card"><i class="fas fa-map-pin"></i> <span>Rm. 201, Health Hub</span></div>
        <div class="contact-card"><i class="fas fa-phone"></i> <span>(02) 8877 4412</span></div>
        <div class="contact-card"><i class="fas fa-envelope"></i> <span>icare@clinic.edu</span></div>
      </div>
      <!-- small badge: no register option needed -->
      <div style="text-align: center; margin-top: 3rem; opacity: 0.6;">
        <i class="fas fa-shield-alt"></i> secure access · staff & clinic personnel only
      </div>
    </div>
  </section>

  <!-- footer -->
  <footer class="footer">
    <div class="container">
      <div style="display: flex; align-items: center; gap: 0.5rem;">
        <img src="assets/images/clinic.png" alt="" style="height: 28px; width: 28px; object-fit: contain;" onerror="this.style.display='none'">
        <small>© 2026 ICARE — school clinic for grades 11–4th year. all rights reserved.</small>
      </div>
      <div class="footer-links">
        <a href="#home"><i class="fas fa-chevron-up"></i> top</a>
        <a href="#">privacy</a>
        <a href="login.php"><i class="fas fa-lock"></i> login</a>
      </div>
    </div>
  </footer>

  <!-- subtle hover animation assets -->
</body>
</html>