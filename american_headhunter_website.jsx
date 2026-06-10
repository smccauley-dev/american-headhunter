import { useState, useEffect } from "react";

const styles = `
  @import url('https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,300;1,9..144,400;1,9..144,500&family=Crimson+Pro:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=JetBrains+Mono:wght@300;400;500;600&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --ink:        #0a1512;
    --ink-soft:   #142420;
    --ink-lift:   #1c302a;
    --parchment:  #e8dcc4;
    --parch-dim:  #c9b896;
    --parch-deep: #a89874;
    --blaze:      #c84c21;
    --blaze-dim:  #8a3216;
    --sage:       #6b7856;
    --sage-dim:   #4a5440;
    --brass:      #b8934a;
    --brass-dim:  #7a6028;
    --bone:       #f4ecdc;
    --rust:       #722814;

    --display:    'Fraunces', Georgia, serif;
    --body:       'Crimson Pro', Georgia, serif;
    --mono:       'JetBrains Mono', Menlo, monospace;
  }

  html { scroll-behavior: smooth; }
  body {
    font-family: var(--body);
    background: var(--parchment);
    color: var(--ink);
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    font-size: 17px;
    line-height: 1.6;
  }

  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: var(--parch-dim); }
  ::-webkit-scrollbar-thumb { background: var(--ink); }

  .topo-bg {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 800' fill='none' stroke='%23a89874' stroke-width='0.6' opacity='0.35'%3E%3Cpath d='M0 100 Q 200 80, 400 140 T 800 120'/%3E%3Cpath d='M0 160 Q 180 140, 380 200 T 800 180'/%3E%3Cpath d='M0 220 Q 220 200, 420 260 T 800 240'/%3E%3Cpath d='M0 280 Q 200 260, 400 320 T 800 300'/%3E%3Cpath d='M0 340 Q 180 320, 380 380 T 800 360'/%3E%3Cpath d='M0 400 Q 220 380, 420 440 T 800 420'/%3E%3Cpath d='M0 460 Q 200 440, 400 500 T 800 480'/%3E%3Cpath d='M0 520 Q 180 500, 380 560 T 800 540'/%3E%3Cpath d='M0 580 Q 220 560, 420 620 T 800 600'/%3E%3Cpath d='M0 640 Q 200 620, 400 680 T 800 660'/%3E%3Cpath d='M0 700 Q 180 680, 380 740 T 800 720'/%3E%3C/svg%3E");
    background-size: 800px 800px;
  }
  .topo-bg-dark {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 800' fill='none' stroke='%23b8934a' stroke-width='0.6' opacity='0.22'%3E%3Cpath d='M0 100 Q 200 80, 400 140 T 800 120'/%3E%3Cpath d='M0 160 Q 180 140, 380 200 T 800 180'/%3E%3Cpath d='M0 220 Q 220 200, 420 260 T 800 240'/%3E%3Cpath d='M0 280 Q 200 260, 400 320 T 800 300'/%3E%3Cpath d='M0 340 Q 180 320, 380 380 T 800 360'/%3E%3Cpath d='M0 400 Q 220 380, 420 440 T 800 420'/%3E%3Cpath d='M0 460 Q 200 440, 400 500 T 800 480'/%3E%3Cpath d='M0 520 Q 180 500, 380 560 T 800 540'/%3E%3Cpath d='M0 580 Q 220 560, 420 620 T 800 600'/%3E%3Cpath d='M0 640 Q 200 620, 400 680 T 800 660'/%3E%3Cpath d='M0 700 Q 180 680, 380 740 T 800 720'/%3E%3C/svg%3E");
    background-size: 800px 800px;
  }

  .reg-mark { position: absolute; width: 24px; height: 24px; border-color: var(--parch-deep); pointer-events: none; }
  .reg-tl { top: 20px; left: 20px; border-top: 1px solid; border-left: 1px solid; }
  .reg-tr { top: 20px; right: 20px; border-top: 1px solid; border-right: 1px solid; }
  .reg-bl { bottom: 20px; left: 20px; border-bottom: 1px solid; border-left: 1px solid; }
  .reg-br { bottom: 20px; right: 20px; border-bottom: 1px solid; border-right: 1px solid; }

  .section-num {
    font-family: var(--mono); font-size: 11px; letter-spacing: 0.15em;
    color: var(--blaze); font-weight: 500; text-transform: uppercase;
    display: inline-flex; align-items: center; gap: 12px;
  }
  .section-num::before { content: ''; display: block; width: 24px; height: 1px; background: var(--blaze); }

  /* NAV */
  .nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: rgba(232,220,196,0.92); backdrop-filter: blur(8px);
    border-bottom: 1px solid transparent; transition: all 0.3s ease;
  }
  .nav.scrolled { border-bottom-color: var(--parch-deep); background: rgba(232,220,196,0.98); }
  .nav-strip {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 40px; border-bottom: 1px solid rgba(168,152,116,0.3);
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.12em; color: var(--sage-dim);
  }
  .nav-strip-left, .nav-strip-right { display: flex; gap: 24px; align-items: center; }
  .strip-dot {
    display: inline-block; width: 4px; height: 4px; border-radius: 50%;
    background: var(--blaze); margin-right: 8px; vertical-align: middle;
  }
  .nav-main { display: flex; align-items: center; justify-content: space-between; padding: 18px 40px; }
  .logo { display: flex; align-items: center; gap: 16px; text-decoration: none; color: inherit; }
  .logo-mark {
    width: 44px; height: 44px; border: 1.5px solid var(--ink);
    display: flex; align-items: center; justify-content: center;
    position: relative; background: var(--parchment);
  }
  .logo-mark-letters {
    font-family: var(--display); font-size: 18px; font-weight: 600;
    line-height: 1; color: var(--ink); letter-spacing: -0.02em;
  }
  .logo-mark::before, .logo-mark::after {
    content: ''; position: absolute; width: 6px; height: 6px; border: 1px solid var(--ink);
  }
  .logo-mark::before { top: -3px; left: -3px; border-right: none; border-bottom: none; }
  .logo-mark::after  { bottom: -3px; right: -3px; border-left: none; border-top: none; }
  .logo-text { display: flex; flex-direction: column; gap: 2px; }
  .logo-name {
    font-family: var(--display); font-size: 22px; font-weight: 500;
    line-height: 1; letter-spacing: -0.01em; color: var(--ink);
  }
  .logo-tag {
    font-family: var(--mono); font-size: 9px; letter-spacing: 0.2em;
    color: var(--sage-dim); text-transform: uppercase; font-weight: 500;
  }
  .nav-links { display: flex; align-items: center; gap: 40px; list-style: none; }
  .nav-links a {
    font-family: var(--display); font-size: 15px; font-weight: 400;
    color: var(--ink); text-decoration: none;
    position: relative; padding-bottom: 4px; transition: color 0.2s;
  }
  .nav-links a::after {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0;
    height: 1px; background: var(--blaze);
    transform: scaleX(0); transform-origin: left; transition: transform 0.3s ease;
  }
  .nav-links a:hover::after { transform: scaleX(1); }
  .nav-actions { display: flex; align-items: center; gap: 16px; }
  .nav-link-text { font-family: var(--display); font-size: 15px; color: var(--ink); text-decoration: none; }
  .nav-cta {
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase;
    color: var(--bone); background: var(--ink);
    padding: 14px 24px; text-decoration: none;
    border: 1px solid var(--ink); transition: all 0.2s;
  }
  .nav-cta:hover { background: var(--blaze); border-color: var(--blaze); color: var(--bone); }

  /* HERO */
  .hero {
    position: relative; min-height: 100vh; padding: 140px 40px 80px;
    background: var(--parchment); overflow: hidden;
  }
  .hero-topo { position: absolute; inset: 0; opacity: 1; pointer-events: none; }
  .hero-grid {
    position: relative; display: grid;
    grid-template-columns: 1fr 420px; gap: 80px;
    max-width: 1400px; margin: 0 auto; align-items: start;
  }
  .hero-left { position: relative; z-index: 1; }
  .hero-eyebrow {
    display: flex; align-items: center; gap: 16px; margin-bottom: 32px;
    opacity: 0; animation: slideRight 0.8s ease 0.2s forwards;
  }
  .eyebrow-line { width: 48px; height: 1px; background: var(--blaze); }
  .eyebrow-text {
    font-family: var(--mono); font-size: 11px; font-weight: 500;
    letter-spacing: 0.25em; color: var(--blaze); text-transform: uppercase;
  }
  .hero-headline {
    font-family: var(--display); font-size: clamp(54px, 8vw, 124px);
    font-weight: 400; line-height: 0.92; letter-spacing: -0.025em;
    color: var(--ink); font-variation-settings: "opsz" 144;
  }
  .hero-headline .line {
    display: block; opacity: 0; transform: translateY(20px);
    animation: fadeUp 1s ease forwards;
  }
  .hero-headline .line-1 { animation-delay: 0.3s; }
  .hero-headline .line-2 { animation-delay: 0.5s; font-style: italic; color: var(--blaze); font-weight: 500; }
  .hero-headline .line-3 { animation-delay: 0.7s; }
  .hero-headline .amp { font-family: var(--display); font-style: italic; font-weight: 300; color: var(--brass); }

  .hero-meta { margin-top: 56px; display: flex; gap: 48px; flex-wrap: wrap; opacity: 0; animation: fadeUp 1s ease 0.9s forwards; }
  .hero-meta-item { border-left: 1px solid var(--parch-deep); padding-left: 16px; }
  .hero-meta-label {
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.18em;
    text-transform: uppercase; color: var(--sage-dim); margin-bottom: 6px;
  }
  .hero-meta-value {
    font-family: var(--display); font-size: 24px; font-weight: 500; color: var(--ink);
  }
  .hero-meta-value em { font-style: italic; color: var(--blaze); font-weight: 400; }

  .hero-right { position: relative; z-index: 1; }
  .field-card {
    background: var(--bone); border: 1px solid var(--ink);
    padding: 32px; position: relative;
    box-shadow: 8px 8px 0 var(--ink);
    opacity: 0; animation: slideInRight 1s ease 1.1s forwards;
  }
  .field-card::before {
    content: ''; position: absolute; top: 8px; left: 8px; right: 8px; bottom: 8px;
    border: 1px dashed var(--parch-deep); pointer-events: none;
  }
  .field-card-header {
    display: flex; justify-content: space-between; align-items: start;
    padding-bottom: 16px; border-bottom: 1px solid var(--parch-deep); margin-bottom: 20px;
  }
  .field-card-label {
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.2em;
    color: var(--sage-dim); text-transform: uppercase; margin-bottom: 4px;
  }
  .field-card-id { font-family: var(--mono); font-size: 11px; color: var(--ink); font-weight: 500; }
  .field-stamp {
    font-family: var(--display); font-size: 11px; font-weight: 600;
    letter-spacing: 0.1em; text-transform: uppercase; color: var(--blaze);
    border: 1.5px solid var(--blaze); padding: 3px 10px; transform: rotate(-6deg);
  }
  .field-title {
    font-family: var(--display); font-size: 26px; font-weight: 500;
    line-height: 1.15; color: var(--ink); margin-bottom: 6px;
  }
  .field-title em { font-style: italic; color: var(--blaze-dim); }
  .field-sub { font-family: var(--body); font-size: 15px; font-style: italic; color: var(--sage-dim); margin-bottom: 20px; }
  .field-rows { display: flex; flex-direction: column; gap: 10px; }
  .field-row {
    display: grid; grid-template-columns: 120px 1fr; gap: 16px;
    align-items: baseline; padding-bottom: 10px;
    border-bottom: 1px dotted var(--parch-deep);
  }
  .field-row:last-child { border-bottom: none; }
  .field-row-label {
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.1em;
    color: var(--sage-dim); text-transform: uppercase;
  }
  .field-row-value { font-family: var(--display); font-size: 15px; color: var(--ink); font-weight: 500; }
  .field-row-value .dim { color: var(--parch-deep); font-weight: 400; }
  .field-footer {
    margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--parch-deep);
    display: flex; justify-content: space-between; align-items: center;
  }
  .field-price { font-family: var(--display); font-size: 24px; font-weight: 600; color: var(--ink); }
  .field-price small { font-family: var(--body); font-size: 13px; font-weight: 400; color: var(--sage-dim); font-style: italic; }
  .field-cta {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: 0.15em; color: var(--blaze);
    text-transform: uppercase; text-decoration: none;
    display: flex; align-items: center; gap: 8px;
  }
  .field-cta:hover { gap: 12px; transition: gap 0.2s; }

  .hero-actions { display: flex; gap: 16px; margin-top: 48px; opacity: 0; animation: fadeUp 1s ease 1s forwards; }
  .btn-solid {
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase;
    padding: 16px 32px; text-decoration: none;
    background: var(--ink); color: var(--bone); border: 1px solid var(--ink);
    display: inline-flex; align-items: center; gap: 10px; transition: all 0.25s;
  }
  .btn-solid:hover { background: var(--blaze); border-color: var(--blaze); }
  .btn-outline {
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase;
    padding: 16px 32px; text-decoration: none;
    background: transparent; color: var(--ink); border: 1px solid var(--ink); transition: all 0.25s;
  }
  .btn-outline:hover { background: var(--ink); color: var(--bone); }

  .compass {
    position: absolute; right: 60px; bottom: 60px; width: 140px; height: 140px;
    opacity: 0.5; animation: rotateSlow 120s linear infinite; pointer-events: none;
  }

  /* SEARCH */
  .search-section {
    background: var(--ink); padding: 0;
    border-top: 1px solid var(--brass-dim); border-bottom: 1px solid var(--brass-dim);
    position: relative;
  }
  .search-label-strip {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 40px; border-bottom: 1px solid rgba(184,147,74,0.2);
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.15em; color: var(--brass); text-transform: uppercase;
  }
  .search-bar { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; align-items: stretch; }
  .search-field { padding: 20px 28px; border-right: 1px solid rgba(184,147,74,0.2); position: relative; }
  .search-field:last-of-type { border-right: none; }
  .search-field label {
    display: block; font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: 0.2em; text-transform: uppercase; color: var(--brass); margin-bottom: 8px;
  }
  .search-field select, .search-field input {
    background: transparent; border: none; outline: none;
    font-family: var(--display); font-size: 17px; font-weight: 500;
    color: var(--bone); cursor: pointer; width: 100%; appearance: none; padding-right: 20px;
  }
  .search-field select option { background: var(--ink); color: var(--bone); }
  .search-field::after {
    content: '▾'; position: absolute; right: 20px; bottom: 22px;
    color: var(--brass); font-size: 12px; pointer-events: none;
  }
  .search-submit {
    background: var(--blaze); border: none; cursor: pointer; padding: 0 40px;
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    letter-spacing: 0.18em; text-transform: uppercase; color: var(--bone);
    display: flex; align-items: center; gap: 12px; transition: background 0.2s; white-space: nowrap;
  }
  .search-submit:hover { background: var(--rust); }

  /* CHAPTER */
  .chapter { padding: 120px 40px; position: relative; }
  .chapter-header {
    max-width: 1400px; margin: 0 auto 80px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: end;
  }
  .chapter-heading {
    font-family: var(--display); font-size: clamp(40px, 5vw, 68px);
    font-weight: 400; line-height: 1; letter-spacing: -0.02em;
    color: var(--ink); margin-top: 20px;
  }
  .chapter-heading em { font-style: italic; color: var(--blaze); font-weight: 500; }
  .chapter-lede {
    font-family: var(--body); font-size: 18px; font-weight: 300;
    line-height: 1.6; color: var(--ink-lift);
    padding-left: 24px; border-left: 1px solid var(--parch-deep);
  }

  /* PROPERTIES */
  .properties-chapter { background: var(--bone); position: relative; }
  .properties-grid {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px;
  }
  .prop-card {
    background: var(--parchment); border: 1px solid var(--ink); position: relative;
    cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease;
    display: flex; flex-direction: column;
  }
  .prop-card:hover { transform: translate(-4px, -4px); box-shadow: 8px 8px 0 var(--ink); }
  .prop-img {
    aspect-ratio: 16/10; position: relative; overflow: hidden;
    border-bottom: 1px solid var(--ink);
  }
  .prop-img-1 { background: linear-gradient(to bottom, #c4b494 0%, #8a9264 30%, #4a5438 70%, #2a3020 100%); }
  .prop-img-2 { background: linear-gradient(to bottom, #d4c8a8 0%, #a89a6e 25%, #5e5a3a 65%, #2a2818 100%); }
  .prop-img-3 { background: linear-gradient(to bottom, #b8b09a 0%, #7a7258 30%, #3a3825 70%, #1a1808 100%); }
  .prop-img-4 { background: linear-gradient(to bottom, #c8b898 0%, #8c7e58 30%, #483c22 70%, #241c08 100%); }
  .prop-img-5 { background: linear-gradient(to bottom, #a4b0a0 0%, #6e7862 30%, #3e4432 70%, #181c10 100%); }
  .prop-img-6 { background: linear-gradient(to bottom, #c0a888 0%, #85724a 30%, #483c22 70%, #241808 100%); }
  .prop-img::before {
    content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 40%;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 120' preserveAspectRatio='none'%3E%3Cpath d='M0,120 L0,85 L15,70 L28,80 L42,55 L56,72 L68,48 L82,65 L95,40 L108,58 L122,32 L135,52 L148,38 L162,60 L175,42 L188,62 L202,30 L215,55 L230,45 L245,68 L258,48 L272,65 L285,38 L298,58 L312,32 L325,55 L338,42 L352,68 L365,48 L378,62 L392,35 L400,52 L400,120 Z' fill='rgba(10,21,18,0.6)'/%3E%3C/svg%3E");
    background-size: cover; background-position: bottom;
  }
  .prop-tag {
    position: absolute; top: 16px; left: 16px;
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase; color: var(--bone);
    background: var(--ink); padding: 6px 12px; border: 1px solid var(--brass);
  }
  .prop-tag.blaze { background: var(--blaze); }
  .prop-coord {
    position: absolute; top: 16px; right: 16px;
    font-family: var(--mono); font-size: 9px; color: var(--bone);
    background: rgba(10,21,18,0.7); padding: 4px 10px;
    letter-spacing: 0.08em; border: 1px solid rgba(244,236,220,0.2);
  }
  .prop-body { padding: 24px; flex: 1; display: flex; flex-direction: column; }
  .prop-location {
    font-family: var(--mono); font-size: 10px; letter-spacing: 0.15em;
    color: var(--sage-dim); text-transform: uppercase; margin-bottom: 10px;
  }
  .prop-name {
    font-family: var(--display); font-size: 24px; font-weight: 500;
    line-height: 1.2; color: var(--ink); margin-bottom: 14px;
  }
  .prop-specs {
    display: flex; flex-wrap: wrap; gap: 12px; padding: 14px 0;
    border-top: 1px solid var(--parch-deep); border-bottom: 1px solid var(--parch-deep);
    margin-bottom: 16px;
  }
  .prop-spec {
    display: flex; flex-direction: column; gap: 2px;
    font-family: var(--mono); font-size: 11px; color: var(--sage-dim); letter-spacing: 0.05em;
  }
  .prop-spec strong {
    font-family: var(--display); font-size: 16px; font-weight: 600;
    color: var(--ink); letter-spacing: 0;
  }
  .prop-spec + .prop-spec { border-left: 1px solid var(--parch-deep); padding-left: 12px; }
  .prop-species { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
  .species-pill {
    font-family: var(--mono); font-size: 9px; font-weight: 500;
    letter-spacing: 0.1em; text-transform: uppercase;
    color: var(--ink-lift); background: transparent;
    border: 1px solid var(--parch-deep); padding: 4px 8px;
  }
  .prop-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 16px; border-top: 1px solid var(--parch-deep); margin-top: auto;
  }
  .prop-price { font-family: var(--display); font-size: 22px; font-weight: 600; color: var(--ink); }
  .prop-price small { font-family: var(--body); font-size: 13px; font-weight: 400; font-style: italic; color: var(--sage-dim); }
  .prop-view {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: 0.15em; color: var(--blaze); text-transform: uppercase;
  }
  .chapter-footer {
    max-width: 1400px; margin: 56px auto 0;
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 32px; border-top: 1px solid var(--parch-deep);
  }
  .chapter-footer-note { font-family: var(--body); font-size: 15px; font-style: italic; color: var(--sage-dim); }
  .chapter-footer-link {
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    letter-spacing: 0.18em; text-transform: uppercase;
    color: var(--ink); text-decoration: none;
    display: inline-flex; align-items: center; gap: 12px;
    padding-bottom: 4px; border-bottom: 1px solid var(--ink);
  }
  .chapter-footer-link:hover { color: var(--blaze); border-color: var(--blaze); }

  /* SPECIES */
  .species-chapter { background: var(--ink); color: var(--bone); position: relative; overflow: hidden; }
  .species-topo { position: absolute; inset: 0; opacity: 1; pointer-events: none; }
  .species-chapter .chapter-heading { color: var(--bone); }
  .species-chapter .chapter-heading em { color: var(--brass); }
  .species-chapter .chapter-lede { color: var(--parch-dim); border-color: var(--brass-dim); }
  .species-chapter .section-num { color: var(--brass); }
  .species-chapter .section-num::before { background: var(--brass); }
  .species-grid {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 0;
    border: 1px solid var(--brass-dim); position: relative; z-index: 1;
  }
  .species-card {
    aspect-ratio: 3/4; border-right: 1px solid var(--brass-dim);
    padding: 24px 20px;
    display: flex; flex-direction: column; justify-content: space-between;
    position: relative; cursor: pointer; transition: background 0.3s ease; background: var(--ink);
  }
  .species-card:last-child { border-right: none; }
  .species-card:hover { background: var(--ink-lift); }
  .species-card:hover .species-glyph { color: var(--blaze); transform: rotate(-4deg) scale(1.1); }
  .species-num { font-family: var(--mono); font-size: 10px; color: var(--brass); letter-spacing: 0.15em; }
  .species-glyph {
    font-family: var(--display); font-size: 72px; font-weight: 300;
    color: var(--brass); line-height: 1; align-self: center;
    font-style: italic; transition: all 0.4s ease;
  }
  .species-foot { display: flex; flex-direction: column; gap: 6px; }
  .species-name { font-family: var(--display); font-size: 18px; font-weight: 500; color: var(--bone); line-height: 1.1; }
  .species-count {
    font-family: var(--mono); font-size: 9px;
    letter-spacing: 0.15em; color: var(--parch-dim); text-transform: uppercase;
  }

  /* STATS */
  .stats-chapter { background: var(--blaze); padding: 72px 40px; position: relative; }
  .stats-grid { max-width: 1400px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; }
  .stat { padding: 0 40px; border-right: 1px solid rgba(10,21,18,0.25); position: relative; }
  .stat:last-child { border-right: none; }
  .stat-num {
    font-family: var(--display); font-size: clamp(48px, 5vw, 80px);
    font-weight: 500; line-height: 1; color: var(--ink);
    letter-spacing: -0.03em; margin-bottom: 12px;
    display: flex; align-items: baseline; gap: 4px;
  }
  .stat-num sup {
    font-family: var(--mono); font-size: 16px; font-weight: 600;
    color: rgba(10,21,18,0.6); vertical-align: super;
  }
  .stat-label {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: 0.25em; text-transform: uppercase;
    color: rgba(10,21,18,0.7); margin-bottom: 4px;
  }
  .stat-sub { font-family: var(--body); font-size: 14px; font-style: italic; color: var(--ink); }

  /* HOW */
  .how-chapter { background: var(--parchment); position: relative; }
  .how-grid {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(3, 1fr); position: relative;
  }
  .how-grid::after {
    content: ''; position: absolute; top: 80px; left: 0; right: 0;
    height: 1px; background: var(--parch-deep); z-index: 0;
  }
  .how-step { padding: 0 32px; position: relative; z-index: 1; }
  .how-step + .how-step { border-left: 1px solid var(--parch-deep); }
  .how-marker {
    width: 48px; height: 48px; background: var(--parchment);
    border: 1.5px solid var(--ink);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 24px;
    font-family: var(--display); font-size: 20px; font-weight: 600; color: var(--ink);
  }
  .how-title {
    font-family: var(--display); font-size: 28px; font-weight: 500;
    line-height: 1.2; color: var(--ink); margin-bottom: 16px;
  }
  .how-title em { font-style: italic; color: var(--blaze); }
  .how-body {
    font-family: var(--body); font-size: 16px; font-weight: 300;
    line-height: 1.65; color: var(--ink-lift); margin-bottom: 20px;
  }
  .how-meta {
    display: flex; flex-direction: column; gap: 8px;
    padding-top: 16px; border-top: 1px solid var(--parch-deep);
  }
  .how-meta-item {
    font-family: var(--mono); font-size: 11px;
    letter-spacing: 0.08em; color: var(--sage-dim);
    display: flex; align-items: center; gap: 10px;
  }
  .how-meta-item::before { content: '✓'; color: var(--blaze); font-size: 14px; font-weight: 600; }

  /* TESTIMONIAL */
  .testimonial-chapter {
    background: var(--ink); color: var(--bone); padding: 120px 40px;
    position: relative; overflow: hidden;
  }
  .testimonial-topo { position: absolute; inset: 0; opacity: 1; pointer-events: none; }
  .testimonial-wrap { max-width: 900px; margin: 0 auto; position: relative; z-index: 1; text-align: center; }
  .testimonial-label {
    display: inline-flex; align-items: center; gap: 12px; margin-bottom: 48px;
    font-family: var(--mono); font-size: 11px; font-weight: 500;
    letter-spacing: 0.25em; color: var(--brass); text-transform: uppercase;
  }
  .testimonial-label::before, .testimonial-label::after {
    content: ''; display: block; width: 40px; height: 1px; background: var(--brass);
  }
  .testimonial-text {
    font-family: var(--display); font-size: clamp(24px, 3.2vw, 42px);
    font-weight: 400; line-height: 1.3; color: var(--bone);
    margin-bottom: 48px; font-style: italic;
    font-variation-settings: "opsz" 144;
  }
  .testimonial-text em { color: var(--brass); font-style: italic; }
  .testimonial-attr { display: flex; align-items: center; justify-content: center; gap: 24px; }
  .testimonial-line { width: 80px; height: 1px; background: var(--brass-dim); }
  .testimonial-who { text-align: left; }
  .testimonial-name {
    font-family: var(--display); font-size: 18px; font-weight: 500;
    color: var(--bone); letter-spacing: 0.02em; margin-bottom: 4px;
  }
  .testimonial-role {
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.15em; color: var(--parch-dim); text-transform: uppercase;
  }
  .testimonial-nav { display: flex; justify-content: center; gap: 24px; margin-top: 64px; }
  .test-dot {
    font-family: var(--mono); font-size: 11px;
    letter-spacing: 0.15em; color: var(--parch-dim);
    text-transform: uppercase; background: transparent;
    border: none; cursor: pointer; padding: 8px 0;
    border-bottom: 1px solid transparent; transition: all 0.25s;
  }
  .test-dot.active { color: var(--brass); border-color: var(--brass); }

  /* CTA */
  .cta-chapter { background: var(--parchment); padding: 140px 40px; position: relative; overflow: hidden; }
  .cta-wrap { max-width: 1000px; margin: 0 auto; text-align: center; position: relative; z-index: 1; }
  .cta-heading {
    font-family: var(--display); font-size: clamp(52px, 7vw, 104px);
    font-weight: 400; line-height: 0.95; letter-spacing: -0.025em;
    color: var(--ink); margin-bottom: 40px;
  }
  .cta-heading em { font-style: italic; color: var(--blaze); font-weight: 500; }
  .cta-sub {
    font-family: var(--body); font-size: 20px; font-weight: 300;
    font-style: italic; line-height: 1.5; color: var(--ink-lift);
    margin-bottom: 48px; max-width: 640px; margin-left: auto; margin-right: auto;
  }
  .cta-buttons { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
  .cta-coords {
    position: absolute; bottom: 40px; left: 50%; transform: translateX(-50%);
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.2em; color: var(--parch-deep); text-transform: uppercase;
  }

  /* FOOTER */
  .footer { background: var(--ink); color: var(--bone); padding: 80px 40px 32px; position: relative; overflow: hidden; }
  .footer-topo { position: absolute; inset: 0; opacity: 1; pointer-events: none; }
  .footer-top {
    max-width: 1400px; margin: 0 auto;
    display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    gap: 56px; padding-bottom: 56px;
    border-bottom: 1px solid var(--brass-dim);
    position: relative; z-index: 1;
  }
  .footer-brand-name {
    font-family: var(--display); font-size: 32px; font-weight: 500;
    color: var(--bone); margin-bottom: 6px; line-height: 1;
  }
  .footer-brand-dot { font-family: var(--display); font-style: italic; color: var(--blaze); }
  .footer-brand-tag {
    font-family: var(--mono); font-size: 10px; font-weight: 500;
    letter-spacing: 0.25em; color: var(--brass); text-transform: uppercase;
    margin-bottom: 24px;
  }
  .footer-desc {
    font-family: var(--body); font-size: 15px; font-weight: 300;
    line-height: 1.6; color: var(--parch-dim);
    max-width: 320px; margin-bottom: 24px;
  }
  .footer-coord {
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.15em; color: var(--brass); text-transform: uppercase;
  }
  .footer-col-title {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: 0.25em; color: var(--brass); text-transform: uppercase;
    margin-bottom: 20px; padding-bottom: 8px;
    border-bottom: 1px solid rgba(184,147,74,0.3);
  }
  .footer-links { list-style: none; display: flex; flex-direction: column; gap: 10px; }
  .footer-links a {
    font-family: var(--display); font-size: 15px; font-weight: 400;
    color: var(--parch-dim); text-decoration: none; transition: color 0.2s;
  }
  .footer-links a:hover { color: var(--bone); }
  .footer-bot {
    max-width: 1400px; margin: 32px auto 0;
    display: flex; justify-content: space-between; align-items: center;
    position: relative; z-index: 1; flex-wrap: wrap; gap: 16px;
  }
  .footer-copy {
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.15em; color: var(--parch-dim); text-transform: uppercase;
  }
  .footer-legal { display: flex; gap: 24px; }
  .footer-legal a {
    font-family: var(--mono); font-size: 10px;
    letter-spacing: 0.15em; color: var(--parch-dim);
    text-decoration: none; text-transform: uppercase; transition: color 0.2s;
  }
  .footer-legal a:hover { color: var(--brass); }

  /* ANIMATIONS */
  @keyframes fadeUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes slideRight { from { opacity: 0; transform: translateX(-16px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes slideInRight { from { opacity: 0; transform: translateX(32px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes rotateSlow { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

  /* RESPONSIVE */
  @media (max-width: 1100px) {
    .hero-grid { grid-template-columns: 1fr; }
    .hero-right { max-width: 500px; }
    .nav-links { display: none; }
    .chapter-header { grid-template-columns: 1fr; gap: 32px; }
    .properties-grid { grid-template-columns: repeat(2, 1fr); }
    .species-grid { grid-template-columns: repeat(3, 1fr); }
    .species-card:nth-child(3n) { border-right: none; }
    .species-card:nth-child(n+4) { border-top: 1px solid var(--brass-dim); }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 40px 0; }
    .stat:nth-child(2) { border-right: none; }
    .how-grid { grid-template-columns: 1fr; gap: 40px; }
    .how-step + .how-step { border-left: none; border-top: 1px solid var(--parch-deep); padding-top: 40px; }
    .how-grid::after { display: none; }
    .footer-top { grid-template-columns: 1fr 1fr; gap: 40px; }
  }
  @media (max-width: 700px) {
    .nav-strip { display: none; }
    .nav-main { padding: 14px 20px; }
    .logo-text { display: none; }
    .hero { padding: 100px 20px 60px; }
    .chapter { padding: 80px 20px; }
    .search-bar { grid-template-columns: 1fr; }
    .search-field { border-right: none; border-bottom: 1px solid rgba(184,147,74,0.2); }
    .search-submit { padding: 20px; }
    .properties-grid { grid-template-columns: 1fr; gap: 24px; }
    .species-grid { grid-template-columns: repeat(2, 1fr); }
    .species-card:nth-child(2n) { border-right: none; }
    .species-card:nth-child(3n) { border-right: 1px solid var(--brass-dim); }
    .species-card:nth-child(n+3) { border-top: 1px solid var(--brass-dim); }
    .stats-grid { grid-template-columns: 1fr; gap: 32px; padding: 0 20px; }
    .stat { padding: 0 0 32px; border-right: none; border-bottom: 1px solid rgba(10,21,18,0.25); }
    .stat:last-child { border-bottom: none; padding-bottom: 0; }
    .footer-top { grid-template-columns: 1fr 1fr; }
  }
`;

const featured = [
  { tag: "Featured", tagStyle: "blaze", coord: "30.88° N · 100.47° W", location: "Kinney County · Texas", name: "Brackettville Whitetail Ranch", acres: "2,840", stands: "18", lease: "Season", species: ["Whitetail","Axis Deer","Hog","Dove"], price: "$14,500", per: "per season", img: "prop-img-1" },
  { tag: "New Listing", coord: "38.37° N · 98.76° W", location: "Barton County · Kansas", name: "Prairie Wind Buck Farm", acres: "680", stands: "8", lease: "Season", species: ["Whitetail","Turkey","Pheasant"], price: "$4,800", per: "per season", img: "prop-img-2" },
  { tag: "At Auction", tagStyle: "blaze", coord: "32.24° N · 87.62° W", location: "Marengo County · Alabama", name: "Black Belt Bottoms", acres: "1,240", stands: "12", lease: "Season", species: ["Whitetail","Turkey","Hog","Waterfowl"], price: "$7,200", per: "current bid", img: "prop-img-3" },
  { tag: "Club Lease", coord: "39.44° N · 91.03° W", location: "Pike County · Missouri", name: "River Bluff Hunting Club", acres: "920", stands: "10", lease: "Annual", species: ["Whitetail","Turkey","Waterfowl"], price: "$5,600", per: "per member", img: "prop-img-4" },
  { tag: "Featured", tagStyle: "blaze", coord: "31.31° N · 84.44° W", location: "Baker County · Georgia", name: "Plantation Oak Preserve", acres: "1,680", stands: "14", lease: "Season", species: ["Quail","Whitetail","Dove"], price: "$9,400", per: "per season", img: "prop-img-5" },
  { tag: "Day Hunt", coord: "33.67° N · 94.15° W", location: "Bowie County · Texas", name: "Red River Bottoms", acres: "540", stands: "6", lease: "Daily", species: ["Whitetail","Hog"], price: "$285", per: "per hunter / day", img: "prop-img-6" },
];

const speciesData = [
  { num: "01", glyph: "D", name: "Whitetail Deer", count: "4,200 properties" },
  { num: "02", glyph: "T", name: "Wild Turkey",    count: "1,800 properties" },
  { num: "03", glyph: "W", name: "Waterfowl",      count: "940 properties" },
  { num: "04", glyph: "H", name: "Wild Hog",       count: "1,100 properties" },
  { num: "05", glyph: "Q", name: "Quail & Upland", count: "620 properties" },
  { num: "06", glyph: "F", name: "Fishing Rights", count: "880 properties" },
];

const steps = [
  { n: "I",   title: "Survey the land", body: "Search thousands of verified hunting properties by species, acreage, terrain, amenities, and county. Every listing is landowner-verified with accurate coordinates and detailed maps.", meta: ["8,400+ active listings","38 states covered","Topographic property maps included"] },
  { n: "II",  title: "Negotiate & sign", body: "Submit an application directly to the landowner. Negotiate terms, review the lease agreement, and sign digitally — all within the platform. State-specific templates, attorney-reviewed.", meta: ["State-specific lease templates","Digital e-signature (ESIGN compliant)","Secure escrow for deposits"] },
  { n: "III", title: "Hunt with confidence", body: "Access gate codes, trail camera feeds, harvest logs, weather alerts, and emergency SOS tools. Everything you need for a safe, successful season — whether you're on 500 acres or 5,000.", meta: ["Gate codes & digital ID cards","Harvest logging & trail cam sync","SOS safety with E-911 routing"] },
];

const testimonials = [
  { text: "I've been leasing the same <em>1,800 acres in Kansas</em> for three seasons now. The platform made it simple to negotiate terms, sign the lease, and coordinate with the landowner — all without a single phone call.", name: "Marcus T.", role: "Hunter — Wichita, Kansas" },
  { text: "As a landowner with three properties across Texas, I've <em>never had better lessees</em>. The background checks and trust scores mean I know who's walking onto my land before they ever get there.", name: "Robert W.", role: "Landowner — Uvalde County, Texas" },
  { text: "The club lease feature was exactly what our 14-member hunting club needed. We split costs fairly, manage our own member roster, and <em>everyone has their own digital ID card</em> that works with the gate.", name: "James H.", role: "Club President — Alabama" },
];

function CompassRose() {
  return (
    <svg className="compass" viewBox="0 0 140 140">
      <circle cx="70" cy="70" r="60" fill="none" stroke="#0a1512" strokeWidth="0.8" />
      <circle cx="70" cy="70" r="50" fill="none" stroke="#0a1512" strokeWidth="0.5" strokeDasharray="2,4" />
      <circle cx="70" cy="70" r="4" fill="#c84c21" />
      <polygon points="70,10 75,65 70,68 65,65" fill="#0a1512" />
      <polygon points="70,130 75,75 70,72 65,75" fill="#0a1512" opacity="0.4" />
      <polygon points="10,70 65,65 68,70 65,75" fill="#0a1512" opacity="0.6" />
      <polygon points="130,70 75,65 72,70 75,75" fill="#0a1512" opacity="0.6" />
      <text x="70" y="8"   textAnchor="middle" fontFamily="Fraunces" fontSize="11" fontWeight="600" fill="#0a1512">N</text>
      <text x="70" y="140" textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">S</text>
      <text x="5"  y="74"  textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">W</text>
      <text x="135" y="74" textAnchor="middle" fontFamily="Fraunces" fontSize="10" fill="#0a1512">E</text>
    </svg>
  );
}

export default function AmericanHeadhunter() {
  const [scrolled, setScrolled] = useState(false);
  const [testimonial, setTestimonial] = useState(0);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 40);
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    const t = setInterval(() => setTestimonial(p => (p + 1) % testimonials.length), 7000);
    return () => clearInterval(t);
  }, []);

  return (
    <>
      <style>{styles}</style>

      <nav className={`nav ${scrolled ? "scrolled" : ""}`}>
        <div className="nav-strip">
          <div className="nav-strip-left"><span><span className="strip-dot"></span>Open for new listings — Season 2026</span></div>
          <div className="nav-strip-right">
            <span>N 32°14'27" W 97°38'52"</span>
            <span>EST. 2025</span>
            <span>VOL. I · ISSUE 01</span>
          </div>
        </div>
        <div className="nav-main">
          <a href="#" className="logo">
            <div className="logo-mark"><span className="logo-mark-letters">AH</span></div>
            <div className="logo-text">
              <span className="logo-name">American Headhunter</span>
              <span className="logo-tag">Hunt Better · Lease Smarter</span>
            </div>
          </a>
          <ul className="nav-links">
            <li><a href="#">Properties</a></li>
            <li><a href="#">Auctions</a></li>
            <li><a href="#">Outfitters</a></li>
            <li><a href="#">Consulting</a></li>
            <li><a href="#">For Landowners</a></li>
          </ul>
          <div className="nav-actions">
            <a href="#" className="nav-link-text">Sign in</a>
            <a href="#" className="nav-cta">Get Started</a>
          </div>
        </div>
      </nav>

      <section className="hero">
        <div className="hero-topo topo-bg"></div>
        <div className="reg-mark reg-tl"></div>
        <div className="reg-mark reg-tr"></div>
        <div className="reg-mark reg-bl"></div>
        <div className="reg-mark reg-br"></div>

        <div className="hero-grid">
          <div className="hero-left">
            <div className="hero-eyebrow">
              <div className="eyebrow-line"></div>
              <span className="eyebrow-text">America's Hunting Lease Marketplace</span>
            </div>
            <h1 className="hero-headline">
              <span className="line line-1">Your land.</span>
              <span className="line line-2">Your season.</span>
              <span className="line line-3">Your <span className="amp">&amp;</span> legacy.</span>
            </h1>
            <div className="hero-meta">
              <div className="hero-meta-item"><div className="hero-meta-label">Listings</div><div className="hero-meta-value">8,400<em>+</em></div></div>
              <div className="hero-meta-item"><div className="hero-meta-label">States</div><div className="hero-meta-value">38<em>.</em></div></div>
              <div className="hero-meta-item"><div className="hero-meta-label">Acres Leased</div><div className="hero-meta-value">4.2M<em>+</em></div></div>
              <div className="hero-meta-item"><div className="hero-meta-label">Trust</div><div className="hero-meta-value">97<em>%</em></div></div>
            </div>
            <div className="hero-actions">
              <a href="#" className="btn-solid">Browse the Atlas →</a>
              <a href="#" className="btn-outline">List Your Land</a>
            </div>
          </div>

          <div className="hero-right">
            <div className="field-card">
              <div className="field-card-header">
                <div>
                  <div className="field-card-label">Field Record</div>
                  <div className="field-card-id">AH-2026-00184</div>
                </div>
                <div className="field-stamp">Verified</div>
              </div>
              <h3 className="field-title">Brackettville <em>Whitetail</em> Ranch</h3>
              <p className="field-sub">Kinney County, Texas — Hill Country edge</p>
              <div className="field-rows">
                <div className="field-row"><div className="field-row-label">Coordinates</div><div className="field-row-value">29.31° N <span className="dim">·</span> 100.42° W</div></div>
                <div className="field-row"><div className="field-row-label">Acreage</div><div className="field-row-value">2,840 <span className="dim">acres</span></div></div>
                <div className="field-row"><div className="field-row-label">Primary Game</div><div className="field-row-value">Whitetail, Axis, Hog</div></div>
                <div className="field-row"><div className="field-row-label">Season</div><div className="field-row-value">Oct 5 <span className="dim">–</span> Jan 22</div></div>
                <div className="field-row"><div className="field-row-label">Max Hunters</div><div className="field-row-value">6 <span className="dim">on lease</span></div></div>
              </div>
              <div className="field-footer">
                <div className="field-price">$14,500 <small>/ season</small></div>
                <a href="#" className="field-cta">View listing →</a>
              </div>
            </div>
          </div>
        </div>

        <CompassRose />
      </section>

      <section className="search-section">
        <div className="search-label-strip">
          <span>◦ Begin your search</span>
          <span>4 filters · 8,400+ results</span>
        </div>
        <div className="search-bar">
          <div className="search-field">
            <label>State</label>
            <select defaultValue="">
              <option value="">All States</option>
              {["Texas","Kansas","Alabama","Missouri","Georgia","Mississippi","Oklahoma","Arkansas","Tennessee","Kentucky","Iowa","Illinois","Ohio","Pennsylvania"].map(s => <option key={s}>{s}</option>)}
            </select>
          </div>
          <div className="search-field">
            <label>Species</label>
            <select defaultValue="">
              <option value="">All Species</option>
              <option>Whitetail Deer</option><option>Mule Deer</option><option>Wild Turkey</option>
              <option>Waterfowl</option><option>Wild Hog</option><option>Quail & Upland</option>
              <option>Dove</option><option>Fishing Rights</option>
            </select>
          </div>
          <div className="search-field">
            <label>Acreage</label>
            <select defaultValue="">
              <option>Any Size</option><option>Under 500 acres</option>
              <option>500–1,000 acres</option><option>1,000–5,000 acres</option>
              <option>5,000+ acres</option>
            </select>
          </div>
          <div className="search-field">
            <label>Lease Type</label>
            <select defaultValue="">
              <option>All Types</option><option>Fixed Price</option><option>Auction</option>
              <option>Club Lease</option><option>Day Hunt</option><option>Multi-Season</option>
            </select>
          </div>
          <button className="search-submit">Search Atlas <span>→</span></button>
        </div>
      </section>

      <section className="chapter properties-chapter">
        <div className="chapter-header">
          <div>
            <div className="section-num">Chapter I — The Atlas</div>
            <h2 className="chapter-heading">Premium <em>hunting land</em>, surveyed and listed.</h2>
          </div>
          <p className="chapter-lede">
            Every property below has been verified by its landowner and independently checked for acreage, access, and accuracy. What you see is what you'll hunt.
          </p>
        </div>

        <div className="properties-grid">
          {featured.map((p, i) => (
            <div key={i} className="prop-card">
              <div className={`prop-img ${p.img}`}>
                <div className={`prop-tag ${p.tagStyle || ""}`}>{p.tag}</div>
                <div className="prop-coord">{p.coord}</div>
              </div>
              <div className="prop-body">
                <div className="prop-location">{p.location}</div>
                <h3 className="prop-name">{p.name}</h3>
                <div className="prop-specs">
                  <div className="prop-spec"><strong>{p.acres}</strong><span>acres</span></div>
                  <div className="prop-spec"><strong>{p.stands}</strong><span>stands</span></div>
                  <div className="prop-spec"><strong>{p.lease}</strong><span>lease</span></div>
                </div>
                <div className="prop-species">
                  {p.species.map((s, idx) => <span key={idx} className="species-pill">{s}</span>)}
                </div>
                <div className="prop-footer">
                  <div className="prop-price">{p.price} <small>{p.per}</small></div>
                  <span className="prop-view">View →</span>
                </div>
              </div>
            </div>
          ))}
        </div>

        <div className="chapter-footer">
          <div className="chapter-footer-note">Showing 6 of 8,400+ verified listings · Updated daily</div>
          <a href="#" className="chapter-footer-link">Open the full Atlas <span>→</span></a>
        </div>
      </section>

      <section className="chapter species-chapter">
        <div className="species-topo topo-bg-dark"></div>
        <div className="chapter-header">
          <div>
            <div className="section-num">Chapter II — The Almanac</div>
            <h2 className="chapter-heading">Find land for <em>what you love</em> to hunt.</h2>
          </div>
          <p className="chapter-lede">
            Browse by species and zero in on properties that specialize in your pursuit. Every listing includes population data, harvest history, and quota information where available.
          </p>
        </div>
        <div className="species-grid">
          {speciesData.map((s, i) => (
            <div key={i} className="species-card">
              <div className="species-num">No. {s.num}</div>
              <div className="species-glyph">{s.glyph}</div>
              <div className="species-foot">
                <div className="species-name">{s.name}</div>
                <div className="species-count">{s.count}</div>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="stats-chapter">
        <div className="stats-grid">
          <div className="stat"><div className="stat-num">8,400<sup>+</sup></div><div className="stat-label">Active Listings</div><div className="stat-sub">Across 38 states</div></div>
          <div className="stat"><div className="stat-num">24K<sup>+</sup></div><div className="stat-label">Hunters &amp; Landowners</div><div className="stat-sub">Verified members nationwide</div></div>
          <div className="stat"><div className="stat-num">97<sup>%</sup></div><div className="stat-label">Renewal Rate</div><div className="stat-sub">Lessees who return next season</div></div>
          <div className="stat"><div className="stat-num">4.2<sup>M</sup></div><div className="stat-label">Acres Under Lease</div><div className="stat-sub">Private land, professionally managed</div></div>
        </div>
      </section>

      <section className="chapter how-chapter">
        <div className="chapter-header">
          <div>
            <div className="section-num">Chapter III — The Expedition</div>
            <h2 className="chapter-heading">From <em>search</em> to first day afield.</h2>
          </div>
          <p className="chapter-lede">
            Three steps from browsing the atlas to hunting your land. Everything you need — contracts, payments, access credentials, safety tools — lives in one place.
          </p>
        </div>
        <div className="how-grid">
          {steps.map((s, i) => (
            <div key={i} className="how-step">
              <div className="how-marker">{s.n}</div>
              <h3 className="how-title">{s.title}</h3>
              <p className="how-body">{s.body}</p>
              <div className="how-meta">
                {s.meta.map((m, idx) => <div key={idx} className="how-meta-item">{m}</div>)}
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="testimonial-chapter">
        <div className="testimonial-topo topo-bg-dark"></div>
        <div className="testimonial-wrap">
          <div className="testimonial-label">Field Journal · Testimonials</div>
          <p className="testimonial-text" dangerouslySetInnerHTML={{__html: `"${testimonials[testimonial].text}"`}} />
          <div className="testimonial-attr">
            <div className="testimonial-line"></div>
            <div className="testimonial-who">
              <div className="testimonial-name">{testimonials[testimonial].name}</div>
              <div className="testimonial-role">{testimonials[testimonial].role}</div>
            </div>
            <div className="testimonial-line"></div>
          </div>
          <div className="testimonial-nav">
            {testimonials.map((_, i) => (
              <button key={i} className={`test-dot ${i === testimonial ? "active" : ""}`} onClick={() => setTestimonial(i)}>
                Entry {String(i + 1).padStart(2, "0")}
              </button>
            ))}
          </div>
        </div>
      </section>

      <section className="cta-chapter">
        <div className="hero-topo topo-bg" style={{opacity: 0.6}}></div>
        <div className="reg-mark reg-tl"></div>
        <div className="reg-mark reg-tr"></div>
        <div className="reg-mark reg-bl"></div>
        <div className="reg-mark reg-br"></div>
        <div className="cta-wrap">
          <div className="section-num" style={{justifyContent: "center", display: "flex", marginBottom: "24px"}}>
            Your next chapter begins
          </div>
          <h2 className="cta-heading">The land is <em>waiting</em>.</h2>
          <p className="cta-sub">
            Join thousands of hunters and landowners across America who trust American Headhunter to manage every acre, every season, every hunt.
          </p>
          <div className="cta-buttons">
            <a href="#" className="btn-solid">Browse all 8,400+ properties →</a>
            <a href="#" className="btn-outline">List your land</a>
          </div>
        </div>
        <div className="cta-coords">N 32°14'27" · W 97°38'52"</div>
      </section>

      <footer className="footer">
        <div className="footer-topo topo-bg-dark"></div>
        <div className="footer-top">
          <div>
            <div className="footer-brand-name">American Headhunter<span className="footer-brand-dot">.</span></div>
            <div className="footer-brand-tag">americanheadhunter.com</div>
            <p className="footer-desc">
              America's most complete hunting lease marketplace. Connecting landowners and hunters — with the technology and trust to make every season the best one yet.
            </p>
            <div className="footer-coord">Est. 2025 · N 32°14'27" · W 97°38'52"</div>
          </div>
          <div>
            <div className="footer-col-title">For Hunters</div>
            <ul className="footer-links">
              <li><a href="#">Browse Properties</a></li>
              <li><a href="#">Live Auctions</a></li>
              <li><a href="#">Outfitter Packages</a></li>
              <li><a href="#">Club Leases</a></li>
              <li><a href="#">Consulting</a></li>
              <li><a href="#">Marketplace</a></li>
            </ul>
          </div>
          <div>
            <div className="footer-col-title">For Landowners</div>
            <ul className="footer-links">
              <li><a href="#">List Your Property</a></li>
              <li><a href="#">Lease Management</a></li>
              <li><a href="#">Pricing Intelligence</a></li>
              <li><a href="#">Background Checks</a></li>
              <li><a href="#">Tax & Expense Tools</a></li>
            </ul>
          </div>
          <div>
            <div className="footer-col-title">Resources</div>
            <ul className="footer-links">
              <li><a href="#">Field Journal</a></li>
              <li><a href="#">Safety & SOS</a></li>
              <li><a href="#">Veteran Program</a></li>
              <li><a href="#">Youth Hunts</a></li>
              <li><a href="#">Conservation</a></li>
            </ul>
          </div>
          <div>
            <div className="footer-col-title">Company</div>
            <ul className="footer-links">
              <li><a href="#">About</a></li>
              <li><a href="#">Press</a></li>
              <li><a href="#">Careers</a></li>
              <li><a href="#">Contact</a></li>
              <li><a href="#">API Access</a></li>
            </ul>
          </div>
        </div>
        <div className="footer-bot">
          <div className="footer-copy">© 2026 American Headhunter · All rights reserved</div>
          <div className="footer-legal">
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
            <a href="#">Lease Agreements</a>
            <a href="#">Cookies</a>
            <a href="#">Accessibility</a>
          </div>
        </div>
      </footer>
    </>
  );
}
