<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ICARE · clinic for the collegiate</title>
  <!-- Font Awesome 6 (free, premium feel icons) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    body {
      background-color: #ECEFF1;      /* soft blue‑grey */
      color: #191970;                 /* midnight navy */
      line-height: 1.4;
      overflow-x: hidden;
    }

    /* smooth animated gradient overlay — subtle movement */
    @keyframes softFlow {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    .dynamic-bg {
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      background: radial-gradient(circle at 30% 40%, rgba(25,25,112,0.02) 0%, transparent 30%),
                  radial-gradient(circle at 80% 70%, rgba(25,25,112,0.03) 0%, transparent 35%),
                  linear-gradient(125deg, #ECEFF1, #dbe3e9, #ECEFF1);
      background-size: 200% 200%;
      animation: softFlow 22s ease infinite;
      z-index: -2;
      pointer-events: none;
    }

    /* floating geometric accents (only two colours) */
    .floating-shape {
      position: fixed;
      background: rgba(25,25,112,0.04);
      border-radius: 64% 36% 41% 59% / 40% 43% 57% 60%;
      z-index: -1;
      filter: blur(18px);
    }
    .shape1 {
      width: 35vmax; height: 35vmax;
      top: -10vh; right: -5vw;
      background: #19197008;
      animation: float 28s infinite alternate ease-in-out;
    }
    .shape2 {
      width: 45vmin; height: 45vmin;
      bottom: 5vh; left: -3vw;
      background: #1919700c;
      border-radius: 73% 27% 58% 42% / 45% 47% 53% 55%;
      animation: float 20s infinite alternate-reverse;
    }
    @keyframes float {
      0% { transform: translate(0, 0) rotate(0deg); }
      100% { transform: translate(3%, 6%) rotate(8deg); }
    }

    /* main container — full viewport with overflow */
    .landscape {
      min-height: 100vh;
      width: 100%;
      display: flex;
      flex-direction: column;
      backdrop-filter: blur(0); /* crisp text */
    }

    /* header with logo & nav (no register) */
    .top-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1.4rem 5%;
      background: rgba(236, 239, 241, 0.55);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(25,25,112,0.08);
      flex-wrap: wrap;
    }

    .logo-area {
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }
    .logo-img {
      width: 52px;
      height: 52px;
      object-fit: contain;
      filter: drop-shadow(0 4px 8px rgba(25,25,112,0.15));
    }
    .clinic-name {
      font-size: 2rem;
      font-weight: 600;
      letter-spacing: -0.5px;
      color: #191970;
      line-height: 1;
    }
    .clinic-name span {
      font-weight: 300;
      font-size: 1rem;
      letter-spacing: 2px;
      display: block;
      margin-top: 4px;
      color: #191970cc;
    }

    .nav-links {
      display: flex;
      gap: 2.2rem;
      align-items: center;
    }
    .nav-links a {
      text-decoration: none;
      color: #191970;
      font-weight: 450;
      font-size: 1.1rem;
      padding: 0.3rem 0.2rem;
      border-bottom: 2px solid transparent;
      transition: 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .nav-links a i {
      font-size: 1.2rem;
      color: #191970dd;
    }
    .nav-links a:hover {
      border-bottom-color: #191970;
      opacity: 0.9;
    }

    /* main grid — 1 out of 1 bespoke layout */
    .hero-grid {
      flex: 1;
      display: grid;
      grid-template-columns: 1.2fr 0.9fr;
      gap: 2rem;
      padding: 3% 5% 4% 5%;
      align-items: center;
    }

    /* left side: dynamic text + info cubes */
    .hero-text {
      max-width: 100%;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #1919700c;
      padding: 0.5rem 1.3rem 0.5rem 1rem;
      border-radius: 60px;
      font-size: 0.95rem;
      font-weight: 500;
      color: #191970;
      border: 1px solid #1919701a;
      margin-bottom: 2rem;
      backdrop-filter: blur(4px);
    }
    .badge i {
      font-size: 1.2rem;
    }

    .main-headline {
      font-size: 3.8rem;
      font-weight: 700;
      line-height: 1.1;
      letter-spacing: -1.5px;
      color: #191970;
      margin-bottom: 1.5rem;
    }
    .main-headline i {
      color: #191970;
      font-size: 3rem;
      margin-right: 0.2rem;
      opacity: 0.7;
    }

    .headline-desc {
      font-size: 1.2rem;
      color: #191970dd;
      max-width: 550px;
      margin-bottom: 2.5rem;
      font-weight: 350;
      border-left: 4px solid #191970;
      padding-left: 1.4rem;
    }

    /* stats / quick facts — premium cards */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1.2rem;
      margin-bottom: 3rem;
    }
    .stat-item {
      background: rgba(236, 239, 241, 0.7);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      padding: 1.3rem 0.8rem;
      border-radius: 32px;
      border: 1px solid rgba(25,25,112,0.15);
      box-shadow: 0 20px 30px -12px rgba(25,25,112,0.1);
      transition: transform 0.2s ease;
      text-align: center;
    }
    .stat-item:hover {
      transform: translateY(-5px);
      background: rgba(236, 239, 241, 0.9);
      border-color: #19197040;
    }
    .stat-icon {
      font-size: 2rem;
      margin-bottom: 0.4rem;
      color: #191970;
    }
    .stat-number {
      font-size: 1.8rem;
      font-weight: 700;
      line-height: 1.2;
      color: #191970;
    }
    .stat-label {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 400;
      color: #191970cc;
    }

    /* feature mini-list */
    .feature-list {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .feature-row {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .feature-row i {
      width: 2rem;
      height: 2rem;
      background: #19197012;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #191970;
      font-size: 1.1rem;
    }
    .feature-row span {
      font-size: 1.05rem;
      font-weight: 430;
      color: #191970;
    }

    /* right side — visual cards & activity feed */
    .right-panel {
      display: flex;
      flex-direction: column;
      gap: 2rem;
    }

    /* main card — after illness / clearance summary */
    .glass-panel {
      background: rgba(236, 239, 241, 0.6);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border-radius: 42px;
      padding: 2rem 1.8rem;
      border: 1px solid #1919701a;
      box-shadow: 0 35px 60px -25px rgba(25,25,112,0.25);
    }

    .card-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 1.4rem;
      font-weight: 600;
      margin-bottom: 1.5rem;
      color: #191970;
    }
    .card-title i {
      font-size: 1.8rem;
    }

    .clearance-feed {
      display: flex;
      flex-direction: column;
      gap: 1.2rem;
    }
    .feed-entry {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px dashed #19197020;
      padding-bottom: 0.8rem;
    }
    .entry-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .entry-icon {
      width: 42px; height: 42px;
      background: #19197010;
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      color: #191970;
    }
    .entry-info h4 {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 2px;
    }
    .entry-info p {
      font-size: 0.85rem;
      color: #191970bb;
    }
    .entry-status {
      background: #19197010;
      padding: 0.3rem 1rem;
      border-radius: 40px;
      font-size: 0.8rem;
      font-weight: 600;
      border: 1px solid #19197030;
    }
    .status-approve {
      background: #19197020;
      border-color: #191970;
      color: #191970;
    }

    /* second card: today’s visits & dispensing */
    .split-card {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.2rem;
    }
    .mini-card {
      background: rgba(236,239,241,0.5);
      backdrop-filter: blur(8px);
      border-radius: 28px;
      padding: 1.5rem 1.2rem;
      border: 1px solid #1919701a;
    }
    .mini-card i {
      font-size: 1.8rem;
      margin-bottom: 0.7rem;
      color: #191970;
    }
    .mini-card h3 {
      font-size: 1.5rem;
      font-weight: 700;
      color: #191970;
    }
    .mini-card p {
      color: #191970dd;
      font-size: 0.9rem;
    }
    .micro-list {
      margin-top: 1rem;
      list-style: none;
    }
    .micro-list li {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9rem;
      margin-bottom: 8px;
      color: #191970;
    }
    .micro-list li i {
      font-size: 0.7rem;
      background: #19197020;
      border-radius: 10px;
      padding: 2px;
    }

    /* bottom area with animated marquee (subtle) */
    .info-ticker {
      margin-top: 1.5rem;
      padding: 1rem 0;
      border-top: 1px solid #1919701a;
      border-bottom: 1px solid #1919701a;
      overflow: hidden;
      white-space: nowrap;
    }
    .ticker-content {
      display: inline-block;
      animation: tickerMove 28s linear infinite;
      font-size: 0.95rem;
      color: #191970cc;
    }
    .ticker-content i {
      margin: 0 1.2rem;
      font-size: 1rem;
      opacity: 0.5;
    }
    @keyframes tickerMove {
      0% { transform: translateX(0); }
      100% { transform: translateX(-38%); }
    }

    /* additional context – live badges */
    .live-dots {
      display: flex;
      gap: 0.4rem;
      align-items: center;
      margin-top: 1rem;
      font-size: 0.8rem;
      font-weight: 400;
      color: #191970;
    }
    .dot-pulse {
      width: 8px; height: 8px;
      background: #191970;
      border-radius: 50%;
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }

    /* footer minimal */
    .footer-note {
      text-align: center;
      padding: 1rem 5%;
      color: #191970aa;
      font-size: 0.9rem;
      border-top: 1px solid #19197018;
      background: #eceff13a;
      backdrop-filter: blur(4px);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .footer-note i {
      margin: 0 6px;
    }

    /* responsive touch */
    @media (max-width: 950px) {
      .hero-grid { grid-template-columns: 1fr; }
      .main-headline { font-size: 3rem; }
    }
    @media (max-width: 550px) {
      .top-bar { flex-direction: column; align-items: start; gap: 1rem; }
      .stat-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <!-- dynamic background layers -->
  <div class="dynamic-bg"></div>
  <div class="floating-shape shape1"></div>
  <div class="floating-shape shape2"></div>

  <!-- main LANDSCAPE (1 out of 1 full experience) -->
  <div class="landscape">

    <!-- header with logo (assets/images/clinic.png) and no register -->
    <div class="top-bar">
      <div class="logo-area">
        <!-- premium logo display using given path -->
        <img src="assets/images/clinic.png" alt="ICARE insignia" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="clinic-name">ICARE<span>collegiate clinic · grade 11 – 4th year</span></div>
      </div>
      <div class="nav-links">
        <a href="#"><i class="fas fa-clinic-medical"></i> dashboard</a>
        <a href="#"><i class="fas fa-notes-medical"></i> clearances</a>
        <a href="#"><i class="fas fa-history"></i> visits</a>
        <!-- no register, only premium icons -->
      </div>
    </div>

    <!-- main hero grid — 1/1 bespoke layout -->
    <div class="hero-grid">

      <!-- LEFT SIDE: everything context, animation, stats -->
      <div class="hero-text">
        <div class="badge">
          <i class="fas fa-shield-heart"></i> dedicated to grades 11–college · 24/7 RN
        </div>
        <h1 class="main-headline">
          <i class="fas fa-plus-circle"></i> beyond <br>first aid
        </h1>
        <div class="headline-desc">
          where health meets academic resilience — clearance, emergency, & wellness under one roof.
        </div>

        <!-- animated stats blocks (real data feel) -->
        <div class="stat-grid">
          <div class="stat-item">
            <i class="fas fa-file-prescription stat-icon"></i>
            <div class="stat-number">276</div>
            <div class="stat-label">clearances (Feb)</div>
          </div>
          <div class="stat-item">
            <i class="fas fa-truck-medical stat-icon"></i>
            <div class="stat-number">13</div>
            <div class="stat-label">emergencies</div>
          </div>
          <div class="stat-item">
            <i class="fas fa-capsules stat-icon"></i>
            <div class="stat-number">38</div>
            <div class="stat-label">dispensations</div>
          </div>
        </div>

        <!-- feature row with icons (premium, no emoji) -->
        <div class="feature-list">
          <div class="feature-row">
            <i class="fas fa-file-certificate"></i>
            <span>Fit-to-return certificates · post‑illness assessment</span>
          </div>
          <div class="feature-row">
            <i class="fas fa-person-walking"></i>
            <span>Work immersion / sports clearance within 2h</span>
          </div>
          <div class="feature-row">
            <i class="fas fa-syringe"></i>
            <span>vaccination & deworming drives (onsite)</span>
          </div>
          <div class="feature-row">
            <i class="fas fa-heart-pulse"></i>
            <span>physical exam & screening events</span>
          </div>
        </div>

        <!-- live pulse indicator + subtle motion -->
        <div class="live-dots">
          <span class="dot-pulse"></span> <span>clinic · 7 attendees now</span>
          <i class="fas fa-chevron-right" style="margin-left: auto; opacity: 0.5;"></i>
        </div>
      </div>

      <!-- RIGHT PANEL: rich interactive cards (lots happening) -->
      <div class="right-panel">

        <!-- clearance / requests glass card (dynamic) -->
        <div class="glass-panel">
          <div class="card-title">
            <i class="fas fa-pen-ruler"></i> recent clearance requests
          </div>
          <div class="clearance-feed">
            <div class="feed-entry">
              <div class="entry-left">
                <div class="entry-icon"><i class="fas fa-user-graduate"></i></div>
                <div class="entry-info">
                  <h4>Pesuelo, Manuel D. · 4th Yr</h4>
                  <p>sports clearance #CLR-20260227-2739</p>
                </div>
              </div>
              <div class="entry-status">pending</div>
            </div>
            <div class="feed-entry">
              <div class="entry-left">
                <div class="entry-icon"><i class="fas fa-basketball"></i></div>
                <div class="entry-info">
                  <h4>Galido, Kyle T. · Grade 12</h4>
                  <p>after injury · approved (Viray)</p>
                </div>
              </div>
              <div class="entry-status status-approve">approved</div>
            </div>
            <div class="feed-entry">
              <div class="entry-left">
                <div class="entry-icon"><i class="fas fa-lungs"></i></div>
                <div class="entry-info">
                  <h4>Incident #EMG‑20260227‑3388</h4>
                  <p>asthma · referred to east ave</p>
                </div>
              </div>
              <div class="entry-status status-approve">emergency</div>
            </div>
            <div class="feed-entry">
              <div class="entry-left">
                <div class="entry-icon"><i class="fas fa-capsules"></i></div>
                <div class="entry-info">
                  <h4>medicine request #MED-MEF-001</h4>
                  <p>10 capsules · urgent</p>
                </div>
              </div>
              <div class="entry-status status-approve">released</div>
            </div>
          </div>
          <!-- micro stats -->
          <div style="display: flex; justify-content: space-between; margin-top: 1.5rem; color: #191970cc; font-size: 0.9rem; border-top: 1px dashed #19197030; padding-top: 1rem;">
            <span><i class="fas fa-check-circle"></i> 32 cleared today</span>
            <span><i class="fas fa-spinner"></i> 5 pending</span>
          </div>
        </div>

        <!-- double mini-card: stock & visits (with real data) -->
        <div class="split-card">
          <div class="mini-card">
            <i class="fas fa-thermometer-half"></i>
            <h3>38.1 °C</h3>
            <p>last visit: M. Pesuelo (fever)</p>
            <ul class="micro-list">
              <li><i class="fas fa-tablets"></i> Paracetamol 500mg · dispensed</li>
              <li><i class="fas fa-clock"></i> visit #7 · 19:52</li>
              <li><i class="fas fa-user-md"></i> attended by yukki</li>
            </ul>
          </div>
          <div class="mini-card">
            <i class="fas fa-boxes-stacked"></i>
            <h3>clinic stock</h3>
            <p>5 medicines · 4 supplies</p>
            <ul class="micro-list">
              <li><i class="fas fa-capsules"></i> Mefenamic acid (10 caps)</li>
              <li><i class="fas fa-syringe"></i> Gauze pads, tape, masks</li>
              <li><i class="fas fa-exclamation-triangle"></i> 2 items below min</li>
            </ul>
          </div>
        </div>

        <!-- additional high‑impact: physical exam / certificate summary -->
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; justify-content: space-between; background: transparent;">
          <span style="background: #19197010; border-radius: 30px; padding: 0.5rem 1.2rem; border: 1px solid #19197030;"><i class="fas fa-file-waveform" style="margin-right: 8px;"></i> physical exam: K. Galido 25.95 BMI</span>
          <span style="background: #19197010; border-radius: 30px; padding: 0.5rem 1.2rem; border: 1px solid #19197030;"><i class="fas fa-stethoscope"></i> fit for PE · CERT‑20260227‑7997</span>
        </div>

      </div> <!-- end right panel -->
    </div> <!-- end hero grid -->

    <!-- ticker with infinite context (clinic activity) -->
    <div class="info-ticker">
      <div class="ticker-content">
        <i class="fas fa-circle"></i> INC‑20260227‑8914 (slipped, first aid) 
        <i class="fas fa-circle"></i> dispensing log #5 · Paracetamol to M. Pesuelo 
        <i class="fas fa-circle"></i> emergency case #1 · ambulance called 15:35 
        <i class="fas fa-circle"></i> physical exam record #2 · M. Pesuelo 24.22 BMI 
        <i class="fas fa-circle"></i> stock received: surgical masks (SUP-MAS-001) 
        <i class="fas fa-circle"></i> clearance CLR‑20260228‑7244 approved 
        <i class="fas fa-circle"></i> deworming scheduled march 15 
      </div>
    </div>

    <!-- footer with policy links and superadmin touch (no register) -->
    <div class="footer-note">
      <span><i class="far fa-copyright"></i> ICARE collegiate clinic · grade 11–4th year</span>
      <span><i class="fas fa-shield"></i> staff · superadmin </span>
      <span><i class="fas fa-clock"></i> live data 28 feb 2026 14:32</span>
    </div>
  </div>

  <!-- subtle hover micro-interactions (no extra code needed) -->
</body>
</html>