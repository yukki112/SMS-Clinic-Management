<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ICARE · academic health hub</title>
  <!-- strict palette: #191970 (midnight) + #ECEFF1 (blue grey 50) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <!-- Font Awesome 6 (sharp, premium) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: #ECEFF1;
      color: #191970;
      line-height: 1.5;
      scroll-behavior: smooth;
      overflow-x: hidden;
    }

    .container {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 2rem;
    }

    /* typography */
    h1, h2, h3 {
      font-weight: 700;
      letter-spacing: -0.02em;
    }

    /* buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      padding: 0.85rem 2.2rem;
      border-radius: 60px;
      font-weight: 600;
      font-size: 1rem;
      text-decoration: none;
      transition: all 0.3s cubic-bezier(0.2, 0.9, 0.3, 1);
      border: 1.5px solid transparent;
      cursor: pointer;
    }

    .btn-primary {
      background: #191970;
      color: #ECEFF1;
      box-shadow: 0 15px 30px -10px rgba(25, 25, 112, 0.3);
    }
    .btn-primary:hover {
      background: #2a2a9c;
      transform: scale(1.02) translateY(-2px);
      box-shadow: 0 25px 35px -12px rgba(25, 25, 112, 0.4);
    }

    .btn-outline {
      border: 2px solid #191970;
      color: #191970;
      background: transparent;
    }
    .btn-outline:hover {
      background: #191970;
      color: #ECEFF1;
      transform: translateY(-2px);
      box-shadow: 0 15px 25px -8px rgba(25, 25, 112, 0.3);
    }

    /* navigation — cleaner, more structured */
    .navbar {
      padding: 1.25rem 0;
      position: fixed;
      width: 100%;
      top: 0;
      z-index: 100;
      background: rgba(236, 239, 241, 0.8);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
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

    .logo-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .logo-img {
      width: 48px;
      height: 48px;
      object-fit: contain;
      filter: drop-shadow(0 4px 8px rgba(25,25,112,0.15));
    }
    .logo-text {
      font-size: 1.8rem;
      font-weight: 800;
      color: #191970;
      line-height: 1;
    }
    .logo-text span {
      font-weight: 400;
      font-size: 1.4rem;
      opacity: 0.8;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 3rem;
      list-style: none;
    }
    .nav-links a {
      text-decoration: none;
      color: #191970;
      font-weight: 600;
      font-size: 1rem;
      transition: opacity 0.2s;
      opacity: 0.8;
    }
    .nav-links a:hover {
      opacity: 1;
    }
    .nav-links .btn-outline {
      padding: 0.5rem 1.8rem;
      border-width: 1.5px;
    }

    /* HERO — symmetrical, balanced, icon grid fixed */
    .hero {
      min-height: 100vh;
      display: flex;
      align-items: center;
      padding: 7rem 0 3rem;
      background: #ECEFF1;
      position: relative;
      overflow: hidden;
    }

    .hero .container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      align-items: center;
      gap: 3rem;
    }

    .hero h1 {
      font-size: 4rem;
      line-height: 1.1;
      margin-bottom: 1.5rem;
      color: #191970;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      background: rgba(25, 25, 112, 0.06);
      padding: 0.5rem 1.5rem 0.5rem 1.2rem;
      border-radius: 80px;
      margin-bottom: 2rem;
      border: 1px solid rgba(25, 25, 112, 0.25);
      font-weight: 500;
      backdrop-filter: blur(4px);
    }

    .hero p {
      font-size: 1.25rem;
      margin-bottom: 2.8rem;
      color: #191970;
      opacity: 0.8;
      max-width: 90%;
    }

    .hero-buttons {
      display: flex;
      gap: 1.5rem;
      align-items: center;
    }

    /* ICON GRID — redesigned, structured, premium */
    .icon-showcase {
      display: flex;
      flex-direction: column;
      gap: 1.8rem;
    }

    .icon-row {
      display: flex;
      justify-content: space-around;
      gap: 1.5rem;
    }

    .icon-block {
      background: white;
      width: 130px;
      height: 130px;
      border-radius: 42px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.7rem;
      box-shadow: 0 25px 45px -18px rgba(25, 25, 112, 0.3);
      border: 1px solid rgba(25, 25, 112, 0.15);
      transition: all 0.3s ease;
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(8px);
    }

    .icon-block i {
      font-size: 3.2rem;
      color: #191970;
    }

    .icon-block span {
      font-weight: 600;
      font-size: 1.1rem;
      color: #191970;
    }

    .icon-block:hover {
      transform: translateY(-12px) scale(1.03);
      background: white;
      border-color: #191970;
      box-shadow: 0 35px 50px -20px #191970;
    }

    /* floating accent (minimal) */
    .accent-circle {
      position: absolute;
      width: 400px;
      height: 400px;
      border-radius: 50%;
      background: rgba(25, 25, 112, 0.02);
      bottom: -150px;
      right: -100px;
      border: 2px dashed rgba(25, 25, 112, 0.2);
      z-index: 0;
      animation: slowDrift 30s infinite alternate;
    }

    @keyframes slowDrift {
      0% { transform: translate(0, 0) rotate(0deg); }
      100% { transform: translate(-40px, -40px) rotate(10deg); }
    }

    /* FEATURES — card redesign, even spacing */
    .section {
      padding: 6rem 0;
      background: #ECEFF1;
    }

    .section-title {
      font-size: 3rem;
      margin-bottom: 1rem;
      color: #191970;
      text-align: center;
    }

    .section-subhead {
      text-align: center;
      font-size: 1.25rem;
      max-width: 720px;
      margin: 0 auto 4.5rem;
      opacity: 0.75;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1.8rem;
    }

    .feature-card {
      background: white;
      border-radius: 48px;
      padding: 2.5rem 1.8rem;
      border: 1px solid rgba(25, 25, 112, 0.1);
      transition: all 0.3s;
      box-shadow: 0 20px 35px -15px rgba(25, 25, 112, 0.1);
      text-align: left;
    }

    .feature-card:hover {
      border-color: #191970;
      transform: translateY(-8px);
      box-shadow: 0 30px 45px -18px #191970;
    }

    .feature-icon-mid {
      width: 70px;
      height: 70px;
      background: #191970;
      border-radius: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 2rem;
    }

    .feature-icon-mid i {
      font-size: 2.5rem;
      color: #ECEFF1;
    }

    .feature-card h3 {
      font-size: 1.8rem;
      margin-bottom: 0.75rem;
      line-height: 1.2;
    }

    .feature-card p {
      opacity: 0.7;
      font-weight: 400;
    }

    /* ABOUT — crisp stats, no visual clutter */
    .about-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      align-items: center;
      gap: 4rem;
    }

    .about-left h2 {
      font-size: 3rem;
      line-height: 1.2;
      margin-bottom: 1.8rem;
    }

    .about-left p {
      font-size: 1.2rem;
      opacity: 0.75;
      margin-bottom: 2.5rem;
      max-width: 90%;
    }

    .stats-container {
      display: flex;
      gap: 2rem;
    }

    .stat {
      background: white;
      padding: 1.5rem 2rem;
      border-radius: 40px;
      border: 1px solid rgba(25,25,112,0.2);
      min-width: 120px;
    }
    .stat .number {
      font-size: 2.8rem;
      font-weight: 700;
      color: #191970;
      line-height: 1;
    }
    .stat .label {
      font-weight: 500;
      opacity: 0.7;
    }

    .about-right {
      background: white;
      border-radius: 70px 30px 70px 30px;
      padding: 3rem 2.5rem;
      border: 2px solid #19197020;
      display: flex;
      flex-wrap: wrap;
      gap: 2rem;
      justify-content: center;
      box-shadow: 0 30px 40px -20px rgba(25,25,112,0.3);
    }

    .about-right i {
      font-size: 3.5rem;
      color: #191970;
      background: #eceff1;
      width: 90px;
      height: 90px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 30px;
      transition: 0.2s;
    }

    .about-right i:hover {
      background: #191970;
      color: #eceff1;
    }

    /* CONTACT — minimal, sharp */
    .contact-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 2rem;
      justify-content: center;
      margin: 3rem 0 2rem;
    }

    .contact-item {
      background: white;
      padding: 2rem 3rem;
      border-radius: 100px;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      border: 1px solid rgba(25,25,112,0.25);
      flex: 0 1 auto;
      box-shadow: 0 8px 18px rgba(25,25,112,0.05);
    }

    .contact-item i {
      font-size: 2.2rem;
      color: #191970;
    }

    .contact-item span {
      font-weight: 600;
      font-size: 1.3rem;
    }

    .footer {
      padding: 2.2rem 0;
      border-top: 2px solid rgba(25,25,112,0.08);
      background: #eceff1;
    }
    .footer-flex {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .footer small {
      opacity: 0.6;
    }
    .footer-links a {
      color: #191970;
      text-decoration: none;
      margin-left: 2rem;
      font-weight: 500;
      opacity: 0.7;
    }
    .footer-links a:hover { opacity: 1; }

    /* responsive */
    @media (max-width: 1100px) {
      .features-grid { grid-template-columns: repeat(2, 1fr); }
      .hero h1 { font-size: 3.5rem; }
    }
    @media (max-width: 800px) {
      .hero .container { grid-template-columns: 1fr; text-align: center; }
      .hero p { max-width: 100%; }
      .hero-buttons { justify-content: center; }
      .about-wrapper { grid-template-columns: 1fr; }
      .nav-links { gap: 1.5rem; }
    }
  </style>
</head>
<body>
  <nav class="navbar">
    <div class="nav-container">
      <div class="logo-group">
        <img src="assets/images/clinic.png" alt="ICARE" class="logo-img" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'48\' height=\'48\' viewBox=\'0 0 24 24\' fill=\'%23191970\'%3E%3Cpath d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z\'/%3E%3C/svg%3E';">
        <span class="logo-text">ICARE<span>clinic</span></span>
      </div>
      <ul class="nav-links">
        <li><a href="#home">Home</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#contact">Contact</a></li>
        <li><a href="login.php" class="btn btn-outline"><i class="fas fa-arrow-right-to-bracket"></i> Login</a></li>
      </ul>
    </div>
  </nav>

  <!-- HERO – balanced, icon grid straightened -->
  <section id="home" class="hero">
    <div class="accent-circle"></div>
    <div class="container">
      <div class="hero-content">
       
        <h1>where health<br>meets<span style="background: #191970; color: #ECEFF1; padding: 0 0.8rem;">academics</span></h1>
        <p>ICARE clinic: digital clearance, instant records, and smart visits — built exclusively for senior high & tertiary students.</p>
        <div class="hero-buttons">
          <a href="login.php" class="btn btn-primary"><i class="fas fa-shield"></i>login</a>
          <a href="#features" class="btn btn-outline"><i class="fas fa-compass"></i> discover</a>
        </div>
        <!-- subtle stat line -->
        <div style="margin-top: 3rem; display: flex; gap: 2.5rem;">
          <div><i class="fas fa-id-card" style="color: #191970;"></i> <strong>2.4k+</strong> <span style="opacity: 0.6;">students</span></div>
          <div><i class="fas fa-clock"></i> <strong>real‑time</strong> <span style="opacity: 0.6;">updates</span></div>
        </div>
      </div>

      <!-- ICON GRID – completely rebuilt, structured, no more mess -->
      <div class="icon-showcase">
        <div class="icon-row">
          <div class="icon-block"><i class="fas fa-notes-medical"></i><span>clearance</span></div>
          <div class="icon-block"><i class="fas fa-stethoscope"></i><span>exam</span></div>
        </div>
        <div class="icon-row">
          <div class="icon-block"><i class="fas fa-syringe"></i><span>vaccine</span></div>
          <div class="icon-block"><i class="fas fa-heart-pulse"></i><span>vitals</span></div>
        </div>
        <div class="icon-row">
          <div class="icon-block"><i class="fas fa-truck-medical"></i><span>emergency</span></div>
          <div class="icon-block"><i class="fas fa-clipboard"></i><span>visit log</span></div>
        </div>
      </div>
    </div>
  </section>

  <!-- FEATURES (cards 4x) -->
  <section id="features" class="section">
    <div class="container">
      <h2 class="section-title">clinic tools, <span style="background: #191970; color: #ECEFF1; padding: 0.2rem 1rem;">streamlined</span></h2>
      <p class="section-subhead">Everything for higher‑ed health — from stock to certificates.</p>

      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon-mid"><i class="fas fa-file-signature"></i></div>
          <h3>clearance hub</h3>
          <p>Sports, illness, work immersion — fit‑to‑return & certificates with QR validity.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon-mid"><i class="fas fa-bolt"></i></div>
          <h3>incidents & emergency</h3>
          <p>Log parent notifications, ambulance calls, and referral tracking.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon-mid"><i class="fas fa-pills"></i></div>
          <h3>medicine stock</h3>
          <p>Expiry alerts, requests, dispensing logs — full inventory control.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon-mid"><i class="fas fa-notes-medical"></i></div>
          <h3>physical exams</h3>
          <p>Record vitals, BMI, vision, dental — attached to student ID.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ABOUT + stats (clean) -->
  <section id="about" class="section" style="padding-top: 0;">
    <div class="container">
      <div class="about-wrapper">
        <div class="about-left">
          <span class="badge" style="margin-bottom: 1.5rem;"><i class="fas fa-building-columns"></i> only for 11–4th year</span>
          <h2>one clinic, four<br>crucial years.</h2>
          <p>We focus on senior high and college: clearance workflows, medical history, and parental reach — without the K–10 noise. ICARE integrates with your existing student ID system.</p>
          <div class="stats-container">
            <div class="stat"><span class="number">4</span><span class="label"> year levels</span></div>
            <div class="stat"><span class="number">98%</span><span class="label"> faster clearance</span></div>
          </div>
        </div>
        <div class="about-right">
          <!-- icons with interaction -->
          <i class="fas fa-laptop-medical"></i>
          <i class="fas fa-id-card"></i>
          <i class="fas fa-phone-alt"></i>
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </section>

  <!-- CONTACT (no register, crisp) -->
  <section id="contact" class="section" style="background: #eceff1;">
    <div class="container">
      <h2 class="section-title">get in touch</h2>
      <p class="section-subhead" style="margin-bottom: 2rem;">campus clinic · main building, room 201</p>
      <div class="contact-grid">
        <div class="contact-item"><i class="fas fa-map-location-dot"></i> <span>ICARE clinic</span></div>
        <div class="contact-item"><i class="fas fa-phone"></i> <span>(02) 8877 4412</span></div>
        <div class="contact-item"><i class="fas fa-envelope"></i> <span>icare@clinic.edu</span></div>
      </div>
      <div style="text-align: center; margin-top: 2.8rem; opacity: 0.7;">
        <i class="fas fa-shield-heart"></i> secure staff area · no public registration
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer class="footer">
    <div class="container footer-flex">
      <div style="display: flex; align-items: center; gap: 0.8rem;">
        <img src="assets/images/clinic.png" style="height: 30px; width: 30px; object-fit: contain;" onerror="this.style.display='none'" alt="">
        <small>© 2026 ICARE — grades 11–college clinic. all rights reserved.</small>
      </div>
      <div class="footer-links">
        <a href="#home">home</a>
        <a href="#features">features</a>
        <a href="login.php"><i class="fas fa-lock"></i> login</a>
      </div>
    </div>
  </footer>
</body>
</html>