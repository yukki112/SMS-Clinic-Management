<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ICARE · school clinic</title>
  <!-- Poppins & premium subtle icons (Feather / Lucide style via simple SVG) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: "Inter", system-ui, -apple-system, sans-serif;
      background-color: #ECEFF1;      /* soft cool grey */
      color: #191970;                  /* midnight navy */
      line-height: 1.4;
      scroll-behavior: smooth;
    }

    /* container */
    .container {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 2rem;
    }

    /* colour assets */
    .bg-navy { background-color: #191970; }
    .bg-soft { background-color: #ECEFF1; }
    .text-navy { color: #191970; }
    .text-soft { color: #ECEFF1; }

    /* navigation — clean, no background, just navy/soft */
    .navbar {
      padding: 1.5rem 0;
      position: sticky;
      top: 0;
      z-index: 50;
      background-color: rgba(236, 239, 241, 0.85);  /* soft with blur */
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(25, 25, 112, 0.08);
    }

    .nav-container {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .logo-wrapper {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .logo-img {
      height: 44px;
      width: auto;
      display: block;
      filter: drop-shadow(0 2px 4px rgba(25,25,112,0.1));
    }

    .logo-text {
      font-size: 1.7rem;
      font-weight: 600;
      letter-spacing: -0.5px;
      color: #191970;
      line-height: 1;
    }
    .logo-text span {
      font-weight: 300;
      font-size: 1rem;
      margin-left: 0.2rem;
      color: #191970;
      opacity: 0.75;
    }

    .nav-links {
      display: flex;
      gap: 2.5rem;
      align-items: center;
    }

    .nav-links a {
      text-decoration: none;
      font-weight: 500;
      color: #191970;
      font-size: 1rem;
      transition: opacity 0.2s;
      border-bottom: 2px solid transparent;
      padding-bottom: 0.25rem;
    }
    .nav-links a:hover {
      opacity: 0.7;
      border-bottom-color: #191970;
    }

    .btn {
      display: inline-block;
      padding: 0.6rem 1.8rem;
      border-radius: 40px;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.25s ease;
      font-size: 0.95rem;
      border: 1.5px solid transparent;
    }

    .btn-primary {
      background-color: #191970;
      color: #ECEFF1;
      box-shadow: 0 4px 12px rgba(25, 25, 112, 0.2);
    }
    .btn-primary:hover {
      background-color: #0f0f5a;
      transform: translateY(-3px);
      box-shadow: 0 12px 24px rgba(25, 25, 112, 0.3);
    }

    .btn-outline {
      border-color: #191970;
      color: #191970;
      background: transparent;
    }
    .btn-outline:hover {
      background-color: #191970;
      color: #ECEFF1;
      transform: translateY(-2px);
    }

    /* HERO section — full of life, icons & animation */
    .hero {
      padding: 4rem 0 5rem;
      overflow: hidden;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 3rem;
      align-items: center;
    }

    .hero h1 {
      font-size: 3.2rem;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: -1px;
      color: #191970;
      margin-bottom: 1.5rem;
    }

    .hero h1 .smaller {
      display: block;
      font-size: 1.8rem;
      font-weight: 400;
      opacity: 0.8;
      margin-bottom: 0.5rem;
    }

    .hero p {
      font-size: 1.2rem;
      color: #2a2a5e;
      margin-bottom: 2.5rem;
      max-width: 90%;
    }

    .hero-stats {
      display: flex;
      gap: 2.5rem;
      margin-bottom: 2.5rem;
    }

    .stat-item {
      display: flex;
      flex-direction: column;
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      color: #191970;
    }

    .stat-label {
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      opacity: 0.7;
    }

    .hero-buttons {
      display: flex;
      gap: 1.5rem;
      align-items: center;
    }

    /* animated card on the right */
    .hero-visual {
      position: relative;
      height: 400px;
      width: 100%;
      background: rgba(25, 25, 112, 0.02);
      border-radius: 48px;
      box-shadow: inset 0 0 0 1px rgba(25,25,112,0.1);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .floating-icons {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 2rem;
      animation: float 6s infinite alternate ease-in-out;
    }

    .icon-bubble {
      background: #191970;
      width: 100px;
      height: 100px;
      border-radius: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 20px 30px -10px rgba(25,25,112,0.3);
      transition: all 0.2s;
    }

    .icon-bubble svg {
      width: 50px;
      height: 50px;
      stroke: #ECEFF1;
      fill: none;
      stroke-width: 1.7;
    }

    .icon-bubble:nth-child(2) { transform: translateY(30px); background: #0f0f5a; }
    .icon-bubble:nth-child(3) { transform: translateY(-20px); background: #24246b; }
    .icon-bubble:nth-child(4) { transform: translateY(15px); background: #030344; }

    @keyframes float {
      0% { transform: translateY(0px) rotate(-2deg); }
      100% { transform: translateY(-25px) rotate(2deg); }
    }

    /* features / services */
    .section-title {
      font-size: 2.6rem;
      font-weight: 600;
      letter-spacing: -0.02em;
      margin-bottom: 1rem;
      color: #191970;
    }

    .section-sub {
      font-size: 1.1rem;
      max-width: 600px;
      margin-bottom: 3.5rem;
      opacity: 0.8;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 2rem;
      margin: 4rem 0;
    }

    .feature-card {
      background: rgba(255, 255, 255, 0.5);
      backdrop-filter: blur(8px);
      padding: 2.2rem 1.8rem;
      border-radius: 40px;
      border: 1px solid rgba(25, 25, 112, 0.1);
      transition: transform 0.25s ease, box-shadow 0.3s;
      box-shadow: 0 10px 20px -10px rgba(25,25,112,0.1);
    }

    .feature-card:hover {
      transform: scale(1.02) translateY(-10px);
      box-shadow: 0 30px 40px -15px #19197030;
      border-color: #19197020;
    }

    .feature-icon {
      background: #19197010;
      width: 64px;
      height: 64px;
      border-radius: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.8rem;
      border: 1px solid #19197020;
    }

    .feature-icon svg {
      width: 36px;
      height: 36px;
      stroke: #191970;
      stroke-width: 1.5;
      fill: none;
    }

    .feature-card h3 {
      font-size: 1.6rem;
      font-weight: 600;
      margin-bottom: 0.75rem;
    }

    .feature-card p {
      opacity: 0.8;
      font-weight: 400;
    }

    /* clearance / stats strip */
    .strip {
      background: #191970;
      color: #ECEFF1;
      border-radius: 60px;
      padding: 3rem 3.5rem;
      margin: 5rem 0;
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
    }

    .strip-item {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .strip-icon svg {
      width: 40px;
      height: 40px;
      stroke: #ECEFF1;
      stroke-width: 1.5;
      fill: none;
    }

    .strip-text {
      font-size: 1.3rem;
      font-weight: 500;
    }

    /* about + contact combined */
    .about-section {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 3rem;
      margin: 6rem 0;
    }

    .about-card {
      background: #ffffff60;
      backdrop-filter: blur(4px);
      padding: 2.8rem;
      border-radius: 48px;
      border: 1px solid #19197020;
    }

    .about-card h2 {
      font-size: 2rem;
      font-weight: 600;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .about-card p {
      font-size: 1.1rem;
      margin-bottom: 2rem;
      opacity: 0.9;
    }

    .contact-line {
      display: flex;
      align-items: center;
      gap: 1.2rem;
      margin: 1.8rem 0;
    }

    .contact-line svg {
      width: 28px;
      height: 28px;
      stroke: #191970;
      stroke-width: 1.7;
    }

    .footer {
      padding: 2rem 0 3rem;
      text-align: center;
      border-top: 1px solid rgba(25, 25, 112, 0.1);
      margin-top: 2rem;
      font-size: 0.95rem;
    }

    hr {
      border: none;
      border-top: 1px solid rgba(25,25,112,0.1);
      margin: 1rem 0;
    }

    /* small animation */
    .hover-lift {
      transition: transform 0.2s;
    }
    .hover-lift:hover {
      transform: translateY(-6px);
    }

    /* responsive */
    @media (max-width: 900px) {
      .hero-grid { grid-template-columns: 1fr; }
      .hero h1 { font-size: 2.5rem; }
      .nav-links { gap: 1rem; }
      .strip { flex-direction: column; gap: 2rem; align-items: start; }
      .about-section { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <!-- fixed navigation – only login, no register -->
  <nav class="navbar">
    <div class="container nav-container">
      <div class="logo-wrapper">
        <!-- logo from assets/images/clinic.png -->
        <img src="assets/images/clinic.png" alt="ICARE" class="logo-img" onerror="this.style.display='none';">
        <div class="logo-text">ICARE<span>clinic</span></div>
      </div>
      <div class="nav-links">
        <a href="#home">Home</a>
        <a href="#features">Services</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
        <a href="login.php" class="btn btn-primary">Login</a>
        <!-- register button completely removed -->
      </div>
    </div>
  </nav>

  <main>
    <!-- HERO section (1of1 impactful) -->
    <section id="home" class="hero">
      <div class="container hero-grid">
        <div class="hero-content">
          <h1>
            <span class="smaller">welcome to</span>
            ICARE school clinic
          </h1>
          <p>Exclusive health & wellness support for grades 11–12 and college — quiet, professional, and always nearby.</p>
          <!-- premium stats -->
          <div class="hero-stats">
            <div class="stat-item">
              <span class="stat-number">800+</span>
              <span class="stat-label">students</span>
            </div>
            <div class="stat-item">
              <span class="stat-number">24hr</span>
              <span class="stat-label">nurse line</span>
            </div>
            <div class="stat-item">
              <span class="stat-number">100%</span>
              <span class="stat-label">confidential</span>
            </div>
          </div>
          <div class="hero-buttons">
            <a href="#features" class="btn btn-primary">discover services</a>
            <a href="#contact" class="btn btn-outline">emergency? contact</a>
          </div>
        </div>

        <!-- right side animated icons (premium, no emoji) -->
        <div class="hero-visual">
          <div class="floating-icons">
            <!-- icon 1: heart pulse (medical) -->
            <div class="icon-bubble">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 12c0 3.5-2 6-8 10-6-4-8-6.5-8-10 0-3 2-5 8-5s8 2 8 5z" />
                <path d="M12 8v4l2 2" />
              </svg>
            </div>
            <!-- icon 2: shield (safety) -->
            <div class="icon-bubble">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2L3 7v7c0 5 9 8 9 8s9-3 9-8V7l-9-5z" />
                <path d="M12 12v4" /><circle cx="12" cy="9" r="1" fill="currentColor" stroke="none"/>
              </svg>
            </div>
            <!-- icon 3: clipboard / records -->
            <div class="icon-bubble">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                <rect x="8" y="2" width="8" height="4" rx="1" ry="1" />
              </svg>
            </div>
            <!-- icon 4: phone (emergency) -->
            <div class="icon-bubble">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="2" width="14" height="20" rx="2" ry="2" />
                <line x1="12" y1="18" x2="12" y2="18" stroke-width="3" />
              </svg>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- FEATURES section (no emoji icons, only svg) -->
    <section id="features" class="container">
      <h2 class="section-title">premium services</h2>
      <div class="section-sub">designed for senior & college students — modern, calm and thorough.</div>

      <div class="features-grid">
        <!-- feature 1: daily checks -->
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/>
              <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.5"/>
            </svg>
          </div>
          <h3>daily screening</h3>
          <p>Quick vitals & health checks before PE, events, or work immersion.</p>
        </div>
        <!-- feature 2: clearance hub -->
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
              <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
          </div>
          <h3>clearance requests</h3>
          <p>Sports, illness, hospitalization — digital fit-to-return slips.</p>
        </div>
        <!-- feature 3: emergency care -->
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.574 2.81.7A2 2 0 0 1 22 16.92z" />
            </svg>
          </div>
          <h3>emergency & incidents</h3>
          <p>Immediate response, parent notification & referral coordination.</p>
        </div>
        <!-- feature 4: medicine supply -->
        <div class="feature-card">
          <div class="feature-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none">
              <rect x="4" y="4" width="16" height="16" rx="2" ry="2" />
              <line x1="8" y1="12" x2="16" y2="12" />
              <line x1="12" y1="8" x2="12" y2="16" />
            </svg>
          </div>
          <h3>clinic stock & dispensing</h3>
          <p>Track medicines/supplies, dispensing logs & request system.</p>
        </div>
      </div>
    </section>

    <!-- blue strip with useful info / animation -->
    <div class="container">
      <div class="strip">
        <div class="strip-item">
          <span class="strip-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" fill="none">
              <circle cx="12" cy="8" r="4" />
              <path d="M5.5 20v-2a6.5 6.5 0 0 1 13 0v2" />
            </svg>
          </span>
          <span class="strip-text">grades 11–12 & college</span>
        </div>
        <div class="strip-item">
          <span class="strip-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" fill="none">
              <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
              <polyline points="16 3 12 7 8 3" />
            </svg>
          </span>
          <span class="strip-text">confidential records</span>
        </div>
        <div class="strip-item">
          <span class="strip-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" fill="none">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
              <circle cx="12" cy="7" r="4" />
            </svg>
          </span>
          <span class="strip-text">licensed school nurses</span>
        </div>
      </div>
    </div>

    <!-- ABOUT + CONTACT combined (no register, no emoji) -->
    <section id="about" class="container about-section">
      <!-- left: about -->
      <div class="about-card">
        <h2>
          <svg width="36" height="36" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4" />
            <circle cx="12" cy="8" r="0.5" fill="currentColor" stroke="none" />
          </svg>
          about ICARE
        </h2>
        <p>Established exclusively for upper years — a calm, private clinic where students can visit for minor injuries, illness, clearance, or just to rest. We bridge health and academics with zero bureaucracy.</p>
        <p> <strong>• 100% confidential •</strong> electronic health records, deworming, vaccination tracking, and physical exams — all in one place.</p>
        <div style="margin-top: 2rem;">
          <a href="#" class="btn btn-outline" style="border-width: 1.5px;">meet the team</a>
        </div>
      </div>
      <!-- right: contact (no emoji) -->
      <div id="contact" class="about-card">
        <h2>
          <svg width="36" height="36" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none">
            <rect x="2" y="2" width="20" height="20" rx="2.5" ry="2.5" />
            <path d="M7 7h10M7 12h10M7 17h6" />
          </svg>
          contact & location
        </h2>
        <div class="contact-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 2v4M12 22v-4M4 12H2M22 12h-2M19.07 4.93l-2.83 2.83M6.9 17.1l-2.82 2.82M17.1 6.9l2.82-2.82M4.93 19.07l2.83-2.83" />
          </svg>
          <span>campus health hub, near libra building</span>
        </div>
        <div class="contact-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <rect x="2" y="2" width="20" height="20" rx="2.5" ry="2.5" />
            <path d="M7 7l3 3 4-4 3 3" />
          </svg>
          <span>clinic@icare.edu.ph</span>
        </div>
        <div class="contact-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8 10a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.574 2.81.7A2 2 0 0 1 22 16.92z" />
          </svg>
          <span>+63 (2) 8891 2345</span>
        </div>
        <div class="contact-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33-1.82 8 8 0 0 0-14.46 0A1.65 1.65 0 0 0 4.6 15" />
          </svg>
          <span>open 7:30am – 5:30pm (Mon–Fri)</span>
        </div>
        <hr>
        <p style="font-size: 1rem; margin-top: 1rem;">emergency? during events just call the clinic mobile — a nurse is always on duty.</p>
      </div>
    </section>

    <!-- FOOTER (clean, no register link) -->
    <footer class="footer container">
      <p>© 2026 ICARE school clinic — grades 11–4th year college. All rights reserved.</p>
      <div style="display: flex; gap: 2rem; justify-content: center; margin-top: 1.2rem;">
        <a href="#" style="color: #191970; text-decoration: none; font-size: 0.9rem;">privacy</a>
        <a href="#" style="color: #191970; text-decoration: none; font-size: 0.9rem;">staff login</a>
        <a href="#" style="color: #191970; text-decoration: none; font-size: 0.9rem;">emergency</a>
      </div>
    </footer>
  </main>

  <!-- small hover addition (no register) -->
</body>
</html>