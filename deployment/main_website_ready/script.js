/* ═══════════════════════════════════════════════════
   MZ Tech — Website JavaScript
   Nav · Hamburger · Scroll effects · Animations
   Modal · Contact form · Back-to-top
   ═══════════════════════════════════════════════════ */

(function () {
  'use strict';

  /* ── DOM references ──────────────────────────────── */
  const navbar    = document.getElementById('navbar');
  const hamburger = document.getElementById('hamburger');
  const navLinks  = document.getElementById('nav-links');
  const btt       = document.getElementById('btt');
  const form      = document.getElementById('contact-form');
  const formMsg   = document.getElementById('form-message');
  const submitBtn = document.getElementById('submit-btn');

  /* ── SCROLL: nav shadow + back-to-top ───────────── */
  function onScroll() {
    const y = window.scrollY;

    // Nav shadow on scroll
    if (y > 20) {
      navbar.classList.add('scrolled');
    } else {
      navbar.classList.remove('scrolled');
    }

    // Back-to-top button
    if (y > 420) {
      btt.classList.add('visible');
    } else {
      btt.classList.remove('visible');
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll(); // run once on load

  /* ── BACK TO TOP ─────────────────────────────────── */
  btt.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  /* ── HAMBURGER MENU ──────────────────────────────── */
  hamburger.addEventListener('click', function () {
    const isOpen = hamburger.classList.toggle('open');
    navLinks.classList.toggle('open', isOpen);
    hamburger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });

  // Close mobile menu when a nav link is clicked
  navLinks.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      hamburger.classList.remove('open');
      navLinks.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    });
  });

  // Close mobile menu when clicking outside
  document.addEventListener('click', function (e) {
    if (!navbar.contains(e.target) && navLinks.classList.contains('open')) {
      hamburger.classList.remove('open');
      navLinks.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }
  });

  /* ── SMOOTH SCROLL for anchor links ─────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      const href = this.getAttribute('href');
      // Skip bare "#" links (e.g. modal triggers) — not a valid selector
      if (!href || href === '#') return;
      const target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      const offset = 80; // nav height + buffer
      const top = target.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top: top, behavior: 'smooth' });
    });
  });

  /* ── FADE-UP ANIMATION (IntersectionObserver) ────── */
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.08,
      rootMargin: '0px 0px -40px 0px'
    });

    document.querySelectorAll('.fade-up').forEach(function (el) {
      observer.observe(el);
    });
  } else {
    // Fallback: show everything for older browsers
    document.querySelectorAll('.fade-up').forEach(function (el) {
      el.classList.add('visible');
    });
  }

  /* ── MODALS ──────────────────────────────────────── */
  function openModal(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    // Focus first focusable element inside the modal
    const focusable = overlay.querySelector('button, a, input, [tabindex]');
    if (focusable) focusable.focus();
  }

  function closeModal(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  // Open: data-modal triggers (links and buttons)
  document.querySelectorAll('[data-modal]').forEach(function (trigger) {
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      openModal(this.dataset.modal);
    });
  });

  // Close: data-close buttons
  document.querySelectorAll('[data-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeModal(this.dataset.close);
    });
  });

  // Close: click on overlay background
  document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        closeModal(overlay.id);
      }
    });
  });

  // Close: Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.active').forEach(function (overlay) {
        closeModal(overlay.id);
      });
    }
  });

  /* ── SERVICE CARDS → KONTAKTFORMULAR ────────────── */
  document.querySelectorAll('.service-cta').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('[data-service]');
      var serviceValue = card ? card.dataset.service : '';

      // Dropdown vorauswählen
      var select = document.getElementById('service');
      if (select && serviceValue) select.value = serviceValue;

      // Zum Kontaktformular scrollen
      var kontakt = document.getElementById('kontakt');
      if (!kontakt) return;
      var top = kontakt.getBoundingClientRect().top + window.scrollY - 80;
      window.scrollTo({ top: top, behavior: 'smooth' });

      // Formular kurz aufleuchten lassen
      var formWrap = document.querySelector('.contact-form-wrap');
      if (formWrap) {
        setTimeout(function () {
          formWrap.style.transition = 'box-shadow .3s';
          formWrap.style.boxShadow = '0 0 0 4px rgba(0,87,184,.35)';
          setTimeout(function () {
            formWrap.style.boxShadow = '';
          }, 1400);
        }, 500);
      }

      // Namensfeld fokussieren
      setTimeout(function () {
        var nameField = document.getElementById('name');
        if (nameField) nameField.focus();
      }, 700);
    });
  });

  /* ── CONTACT FORM ────────────────────────────────── */
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      // Hide previous messages
      formMsg.className = 'form-message';
      formMsg.textContent = '';

      // Basic validation
      const name    = form.name.value.trim();
      const email   = form.email.value.trim();
      const message = form.message.value.trim();
      const privacy = form.privacy.checked;

      if (!name || !email || !message) {
        formMsg.textContent = 'Bitte füllen Sie alle Pflichtfelder (*) aus.';
        formMsg.className = 'form-message error';
        formMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
      }

      // Simple email format check
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        formMsg.textContent = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        formMsg.className = 'form-message error';
        return;
      }

      if (!privacy) {
        formMsg.textContent = 'Bitte stimmen Sie der Datenschutzerklärung zu.';
        formMsg.className = 'form-message error';
        return;
      }

      // Submit via fetch (AJAX)
      submitBtn.disabled = true;
      submitBtn.textContent = 'Wird gesendet …';

      const data = new FormData(form);

      fetch('contact.php', {
        method: 'POST',
        body: data
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (result) {
        if (result.success) {
          formMsg.textContent = '✓ Vielen Dank! Ihre Nachricht wurde erfolgreich gesendet. Wir melden uns so schnell wie möglich bei Ihnen.';
          formMsg.className = 'form-message success';
          form.reset();
        } else {
          formMsg.textContent = result.message || 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut oder schreiben Sie uns direkt an info@mztech-it.de.';
          formMsg.className = 'form-message error';
        }
      })
      .catch(function () {
        formMsg.textContent = 'Es ist ein Verbindungsfehler aufgetreten. Bitte schreiben Sie uns direkt an info@mztech-it.de.';
        formMsg.className = 'form-message error';
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Nachricht absenden →';
        formMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    });
  }

  /* ── GALERIE LIGHTBOX ───────────────────────────── */
  (function initLightbox() {
    const overlay   = document.getElementById('gallery-lightbox');
    const lbImg     = document.getElementById('lightbox-img');
    const lbCaption = document.getElementById('lightbox-caption');
    const lbClose   = document.getElementById('lightbox-close');
    if (!overlay) return;

    document.querySelectorAll('.gallery-item[data-lightbox]').forEach(function (item) {
      item.addEventListener('click', function () {
        const img     = item.querySelector('img');
        const label   = item.querySelector('.gallery-label');
        lbImg.src     = img.src;
        lbImg.alt     = img.alt;
        lbCaption.textContent = label ? label.textContent : '';
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
      });
    });

    function closeLightbox() {
      overlay.classList.remove('active');
      document.body.style.overflow = '';
      lbImg.src = '';
    }

    lbClose.addEventListener('click', closeLightbox);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeLightbox();
    });
  }());

})();
